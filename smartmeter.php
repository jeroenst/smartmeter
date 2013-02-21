#!php
<?php  
 include "php_serial.class.php";  

// Let's start the class
$serial = new phpSerial;

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

$Electricity_Usage = 0;
$Eelectricity_Used_1 = 0;
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
                if($label[1] == "1-0:2.8.1") $Electricity_Provided_1 = extractfloat($value[1]);
                if($label[1] == "1-0:2.8.2") $Electricity_Provided_2 = extractfloat($value[1]);
                if($label[1] == "") $Gas_Used = extractfloat($value[1]);
              }
              if ($line == "!")
              {
                $receivedpacket = "";
                echo "Received Data (".date('Y/m/d H:i:s')."): gas_used=$Gas_Used, kwh_used1=$Electricity_Used_1, kwh_used2=$Electricity_Used_2, kwh_provided1=$Electricity_Provided_1, kwh_provided2= $Electricity_Provided_2, watt_usage=$Electricity_Usage\n";
                writeEnergyDatabase($Gas_Used, $Electricity_Used_1, $Electricity_Used_2, $Electricity_Provided_1, $Electricity_Provided_2, $Electricity_Usage);
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
$con = mysql_connect("nas","domotica","b-2020");
if (!$con)
  {
    die('Could not connect: ' . mysql_error());
      }
      
      mysql_select_db("domotica", $con);
      
      mysql_query("INSERT INTO energy (gas_used, kwh_used1, kwh_used2, kwh_provided1, kwh_provided2, watt_usage)
      VALUES ($gas_used, $kwh_used1, $kwh_used2, $kwh_provided1, $kwh_provided2, $watt_usage)");
      
      mysql_close($con);
}

?>  
