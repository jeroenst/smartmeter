#!/usr/bin/php
<?php  
 include "php_serial.class.php";  

// Let's start the class
$serial = new phpSerial;
//$Mysql_con = mysql_connect("nas","domotica","b-2020");

// First we must specify the device. This works on both linux and windows (if
// your linux serial device is /dev/ttyS0 for COM1, etc)
while(1)
 {
try
{
echo "Setting Serial Port Device...\n"; 
if ( $serial->deviceSet("/dev/ttyUSB0"))
{
echo "Configuring Serial Port...\n";
// We can change the baud rate, parity, length, stop bits, flow control
$serial->confBaudRate(9600);
$serial->confParity("even");
$serial->confCharacterLength(7);
$serial->confStopBits(1);
$serial->confFlowControl("none");

$serialready=0;
$receivedpacket="";
$start_time = time();
$write_database_timeout = 10; // write database every 10 minutes
$write_database_timer = time();

$Electricity_Usage = 0;
$Electricity_Used_1 = 0;
$Electricity_Used_2 = 0;
$Electricity_Provided_1 = 0;
$Electricity_Provided_2 = 0;
$Gas_Used = 0;
date_default_timezone_set ("Europe/Amsterdam");
echo "Opening Serial Port...\n";
// Then we need to open it
  if ($serial->deviceOpen())
  {
    //Determine if a variable is set and is not NULL
    echo "Waiting for data from Smart Meter...\n";
    while(1)
    {
      // read from serial port
      $read = $serial->readPort();
      if (strlen ($read) == 0) $serialready = 1;
      if ($serialready)
      {
        $receivedpacket = $receivedpacket . $read;   
        $dataprinted = 0;
        if ($receivedpacket != "")
        {
          if (strlen ($read) == 0)
          {
            foreach(preg_split("/((\r?\n)|(\r\n?))/", $receivedpacket) as $line)
            {
              preg_match("'\((.*?)\)'si", $line, $value);
              preg_match("'(.*?)\('si", $line, $label);
              if($label)
              { //echo $label[1]." = ".$value[1]."\n";
                if($label[1] == "1-0:1.7.0") $Electricity_Usage = extractfloat($value[1]) * 1000;
                if($label[1] == "1-0:1.8.1") $Electricity_Used_1 = extractfloat($value[1]);
                if($label[1] == "1-0:1.8.2") $Electricity_Used_2 = extractfloat($value[1]);
                $Electricity_Used = $Electricity_Used_1 + $Electricity_Used_2;
                if($label[1] == "1-0:2.8.1") $Electricity_Provided_1 = extractfloat($value[1]);
                if($label[1] == "1-0:2.8.2") $Electricity_Provided_2 = extractfloat($value[1]);
                if($label[1] == "") $Gas_Used = extractfloat($value[1]);
              }
              if ($line == "!")
              {
                $receivedpacket = "";
                echo "Received Data (".date('Y/m/d H:i:s')."): gas_used=$Gas_Used, kwh_used1=$Electricity_Used_1, kwh_used2=$Electricity_Used_2, kwh_provided1=$Electricity_Provided_1, kwh_provided2= $Electricity_Provided_2, watt_usage=$Electricity_Usage\n";
                if ($write_database_timer < time() )
                {
                  $write_database_timer = time() + ($write_database_timeout * 60);
                  echo "Writing values to database...";
                  writeEnergyDatabase($Gas_Used, $Electricity_Used_1, $Electricity_Used_2, $Electricity_Provided_1, $Electricity_Provided_2, $Electricity_Usage);

		  // Get all the other values from the domotica database  if Gas_Used or Electricity_Used has changed, but only when XMBC is not playing...
		  $mysqlresult = mysql_query("SELECT * FROM `energy` WHERE `timestamp` >= timestampadd(hour, -1, now()) LIMIT 1");
		  $Electricity_Used_Hour=$Electricity_Used - (mysql_result($mysqlresult, 0, "kwh_used1") + mysql_result($mysqlresult, 0, "kwh_used2"));
		  $Gas_Used_Hour=$Gas_Used - mysql_result($mysqlresult, 0, "gas_used");
		  $Gas_Usage=round($Gas_Used_Hour * 1000);


		  $mysqlresult = mysql_query("SELECT * FROM `energy` WHERE `timestamp` >= CURDATE() LIMIT 1");
    $Electricity_Used_Today=$Electricity_Used - (mysql_result($mysqlresult, 0, "kwh_used1") + mysql_result($mysqlresult, 0, "kwh_used2"));
    $Gas_Used_Today=$Gas_Used - mysql_result($mysqlresult, 0, "gas_used");

    $mysqlresult = mysql_query("SELECT * FROM `energy` WHERE `timestamp` >= DATE_SUB(CURDATE(),INTERVAL 7 DAY) LIMIT 1");
    $Electricity_Used_Week=$Electricity_Used - (mysql_result($mysqlresult, 0, "kwh_used1") + mysql_result($mysqlresult, 0, "kwh_used2"));
    $Gas_Used_Week=$Gas_Used - mysql_result($mysqlresult, 0, "gas_used");

    $mysqlresult = mysql_query("SELECT * FROM `energy` WHERE `timestamp` >= DATE_SUB(CURDATE(),INTERVAL 30 DAY) LIMIT 1");
    $Electricity_Used_Month=$Electricity_Used - (mysql_result($mysqlresult, 0, "kwh_used1") + mysql_result($mysqlresult, 0, "kwh_used2"));
    $Gas_Used_Month=$Gas_Used - mysql_result($mysqlresult, 0, "gas_used");

    $mysqlresult = mysql_query("SELECT * FROM `energy` WHERE `timestamp` >= DATE_SUB(CURDATE(),INTERVAL 365 DAY) LIMIT 1");
    $Electricity_Used_Year=$Electricity_Used - (mysql_result($mysqlresult, 0, "kwh_used1") + mysql_result($mysqlresult, 0, "kwh_used2"));
    $Gas_Used_Year=$Gas_Used - mysql_result($mysqlresult, 0, "gas_used");

                }


                echo "Writing values to xml file...\n";
    
                $xml = new SimpleXMLElement('<root/>');
                $xml->addChild("Electricity_Usage" , $Electricity_Usage);
                $xml->addChild("Electricity_Used" , $Electricity_Used);
                $xml->addChild("Electricity_Used_1" , $Electricity_Used_1);
                $xml->addChild("Electricity_Used_2" , $Electricity_Used_2);
                $xml->addChild("Electricity_Used_Today" , $Electricity_Used_Today);
                $xml->addChild("Electricity_Used_Hour" , $Electricity_Used_Hour);
                $xml->addChild("Electricity_Used_Week" , $Electricity_Used_Week);
                $xml->addChild("Electricity_Used_Month" , $Electricity_Used_Month);
                $xml->addChild("Electricity_Used_Year" , $Electricity_Used_Year);
                $xml->addChild("Electricity_Provided" , $Electricity_Provided_1+$Electricity_Provided_2);
                $xml->addChild("Electricity_Provided1" , $Electricity_Provided_1);
                $xml->addChild("Electricity_Provided2" , $Electricity_Provided_2);
                $xml->addChild("Gas_Usage" , $Gas_Usage);
                $xml->addChild("Gas_Used" , $Gas_Used);
                $xml->addChild("Gas_Used_Today" , $Gas_Used_Today);
                $xml->addChild("Gas_Used_Hour" , $Gas_Used_Hour);
                $xml->addChild("Gas_Used_Week" , $Gas_Used_Week);
                $xml->addChild("Gas_Used_Month" , $Gas_Used_Month);
                $xml->addChild("Gas_Used_Year" , $Gas_Used_Year);
            
                $dom = dom_import_simplexml($xml)->ownerDocument;
                $dom->formatOutput = true;
                file_put_contents('/tmp/smartmeter.xml.tmp', $dom->saveXML(), LOCK_EX);
                exec ('mv /tmp/smartmeter.xml.tmp /tmp/smartmeter.xml');
              }
            } 
          }
        }
      }
      sleep (1);
    }// end while
  }
  }
  }
  catch (Exception $e)
  {
    echo "Error thrown, restarting program\n";
  }
  sleep(10);
}
mysql_close($Mysql_con);

// If you want to change the configuration, the device must be closed
$serial->deviceClose();
exit(1);

function extractfloat($string)
{
 $tmp = preg_replace( '/[^\d\.]/', '',  $string );
 return floatval($tmp);
}

function match($lines, $needle) 
{
  $ret = false;
  foreach ( $lines as $line ) 
  {
    list($key,$val) = explode(':',$line);
    $ret = $key==$needle ? $val : false;
    if ( $ret ) break;
  }
  return $ret;
}
                      
                      
function replace(&$lines, $needle, $value, $add=true) 
{
  $ret = false;
  foreach ( $lines as &$line) 
  {
    list($key,$val) = explode(':',$line);
    if ($key==$needle)
    {
      $val = $value;
      $line = $key.':'.$val;
      $ret = true;
    }
  }
  if (($ret == false)&&($add == true))
  {
    array_push ($lines,$needle.':'.$value); 
    $ret = true;
  }
  return $ret;
}                     

function removeEmptyLines(&$linksArray) 
{
  foreach ($linksArray as $key => $link)
  {
    if ($linksArray[$key] == '')
    {
      unset($linksArray[$key]);
    }
  }
}                     

function writeEnergyDatabase($gas_used, $kwh_used1, $kwh_used2, $kwh_provided1, $kwh_provided2, $watt_usage)
{
  static $Mysql_con;
  if (!$Mysql_con)
  {
      $Mysql_con = mysql_connect("server","domotica","dom8899");
  }

  if ($Mysql_con)
  {        
      if (mysql_select_db("domotica", $Mysql_con))
      {
        if (mysql_query("INSERT INTO energy (gas_used, kwh_used1, kwh_used2, kwh_provided1, kwh_provided2, watt_usage)
        VALUES ($gas_used, $kwh_used1, $kwh_used2, $kwh_provided1, $kwh_provided2, $watt_usage)"))
        {
          return 1;
        }
      }
      mysql_close($Mysql_con);
      $Mysql_con = 0;
  }
  return 0;
}

?>  
