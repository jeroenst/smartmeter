#!/usr/bin/php
<?php  


$data = json_decode ('
{
			"electricitymeter": {
				"now":
				{
					"kw_using": null,
					"kw_providing": null
				},
				"total":
				{
					"kwh_used1": null,
					"kwh_used2": null,
					"kwh_provided1": null,
					"kwh_provided2": null
				}
			},
			"gasmeter":
			{
				"now":
				{
					"m3h": null
				},
				"total":
				{
					"m3": null
				}
			}
		
}
');

include "php_serial.class.php";  

$settings = array(	"device" => "/dev/ttyUSB0", 
"mysqlserver" => "localhost", 
"mysqldatabase" => "casaan", 
"mysqlusername" => "casaan",
"mysqlpassword" => "casaan",
"port" => "58881");
if ($argc > 1) 
{
	$settingsfile = parse_ini_file($argv[1]);
	$settings = array_merge($settings, $settingsfile);
}

// Initialize websocket
$tcpsocket = stream_socket_server("tcp://0.0.0.0:".$settings["port"], $errno, $errstr);
if (!$tcpsocket) {
	echo "$errstr ($errno}\n";
	exit(1);
}

$tcpsocketClients = array();
array_push($tcpsocketClients, $tcpsocket);


// Let's start the class
$serial = new phpSerial;
//$Mysql_con = mysql_connect("nas","domotica","b-2020");

// First we must specify the device. This works on both linux and windows (if
// your linux serial device is /dev/ttyS0 for COM1, etc)
while(1)
{
	$readmask = $tcpsocketClients;
	$writemask = NULL;
	$errormask = NULL;
	$mod_fd = stream_select($readmask, $writemask, $errormask, 1);
	foreach ($readmask as $i) 
	{
		if ($i === $tcpsocket) 
		{
			$conn = stream_socket_accept($tcpsocket);
			echo ("\nNew tcpsocket client connected!\n\n");
			array_push($tcpsocketClients, $conn);
			echo ("Sending data to tcp client\n");
			fwrite($conn, json_encode($data));
		}
		else
		{
			$sock_data = fread($i, 1024);
			if (strlen($sock_data) === 0) { // connection closed
				$key_to_del = array_search($i, $tcpsocketClients, TRUE);
				unset($tcpsocketClients[$key_to_del]);
			} else if ($sock_data === FALSE) {
				echo "Something bad happened";
				fclose($i);
				$key_to_del = array_search($i, $tcpsocketClients, TRUE);
				unset($tcpsocketClients[$key_to_del]);
			} else {
				echo ("Received from tcpsocket client: [" . $sock_data . "]\n");
				if (trim($sock_data) == "getsmartmeterdata") 
				{
					echo ("Sending smartmeterdata to tcpsocketclient...\n");
					fwrite($conn, json_encode($data));
				}
			}
		}
	}

	try
	{
		echo "Setting Serial Port Device...\n"; 
		if ( $serial->deviceSet($settings["device"]))
		{
			echo "Configuring Serial Port...\n";
			// We can change the baud rate, parity, length, stop bits, flow control
			echo "Baudrate... ";
			$serial->confBaudRate(9600);
			echo "Parity... ";
			$serial->confParity("even");
			echo "Bits... ";
			$serial->confCharacterLength(7);
			echo "Stopbits... ";
			$serial->confStopBits(1);
			echo "Flowcontrol... ";
			$serial->confFlowControl("none");
			echo "Done...\n";

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
			$Mysql_electricity_table="electricitymeter";
			$Mysql_gas_table="gasmeter";

			echo ($serial->_dHandle."\n");


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
					$packetcomplete = false;
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
									{
										if($label[1] == "1-0:1.7.0") $data['electricitymeter']['now']['kwh_using'] = extractfloat($value[1]);
										if($label[1] == "1-0:2.7.0") $data['electricitymeter']['now']['kwh_providing'] = extractfloat($value[1]);
										if($label[1] == "1-0:1.8.1") $data['electricitymeter']['total']['kwh_used1'] = extractfloat($value[1]);
										if($label[1] == "1-0:1.8.2") $data['electricitymeter']['total']['kwh_used2'] = extractfloat($value[1]);
										if($label[1] == "1-0:2.8.1") $data['electricitymeter']['total']['kwh_provided1'] = extractfloat($value[1]);
										if($label[1] == "1-0:2.8.2") $data['electricitymeter']['total']['kwh_provided2'] = extractfloat($value[1]);
										if($label[1] == "") $data['gasmeter']['total']['m3'] = extractfloat($value[1]);
									}
									if ($line == "!")
									{
										echo "Received Data (".date('Y/m/d H:i:s').")". 
										": gas_used=".$data['gasmeter']['total']['m3'].
										", kwh_used1=".$data['electricitymeter']['total']['kwh_used1'].
										", kwh_used2=".$data['electricitymeter']['total']['kwh_used2'].
										", kwh_provided1=".$data['electricitymeter']['total']['kwh_provided1'].
										", kwh_provided2=".$data['electricitymeter']['total']['kwh_provided2'].
										", kw_using=".$data['electricitymeter']['now']['kw_using']."\n";
										", kw_providing=".$data['electricitymeter']['now']['kw_providing']."\n";

										sendToAllTcpsocketClients($tcpsocketClients, json_encode($data)."\n\n");

										if ($write_database_timer < time() )
										{
											$write_database_timer = time() + ($write_database_timeout * 60);
											echo "Writing values to database...";
											writeEnergyDatabase(
											$data['gasmeter']['total']['m3_used'],
											$data['electricitymeter']['total']['kwhused1'],
											$data['electricitymeter']['total']['kwhused2'],
											$data['electricitymeter']['total']['kwhprovided1'],
											$data['electricitymeter']['total']['kwhprovided2'],
											$data['electricitymeter']['now']['kw_using'],
											$data['electricitymeter']['now']['kw_providing']);
										}

									}
								} 
							}
						}
					}
				}// end while
			}
		}
	}
	catch (Exception $e)
	{
		echo "Error thrown, restarting program\n";
	}
	sleep(1);
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

function writeEnergyDatabase($gas_used, $kwh_used1, $kwh_used2, $kwh_provided1, $kwh_provided2, $kw_using, $kw_providing)
{
	global $settings;

	$mysqli = mysqli_connect($settings["mysqlserver"],$settings["mysqlusername"],$settings["mysqlpassword"],$settings["mysqldatabase"]);

	if (!$mysqli->connect_errno)
	{        
		$mysqli->query("INSERT INTO `electricitymeter` (kw_using, kw_providing, kwh_used1, kwh_used2, kwh_provided1, kwh_provided2)
						VALUES ($kw_using, $kw_providing, $kwh_used1, $kwh_used2, $kwh_provided1, $kwh_provided2)");

		$mysqli->query("INSERT INTO `gasmeter` (m3, m3h) VALUES ($gas_used, 0)");
		$mysqli->close();	
	}
	else
	{
		echo ("Error while writing values to database: ".$mysqli->connect_error ."\n");
	}
	return 0;
}



function sendToAllTcpSocketClients($sockets, $msg)
{
	echo ("Sending smartmeterdata to all websocketclient...\n");
	foreach ($sockets as $conn) 
	{
		fwrite($conn, $msg);
	}
}


?>  
