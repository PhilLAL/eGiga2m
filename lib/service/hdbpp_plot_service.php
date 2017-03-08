<?php

	include './hdbpp_conf.php';
	$timezone = date_default_timezone_get();
	
	$host = 'http://'.$_SERVER["HTTP_HOST"];
	$uri = explode('?', $_SERVER["REQUEST_URI"]);
	$host .= strtr($uri[0], array('/lib/service/hdbpp_plot_service.php'=>''));

	// if (isset($_REQUEST['debug'])) file_put_contents('debug.txt', json_encode($_REQUEST));

	$state = array('ON','OFF','CLOSE','OPEN','INSERT','EXTRACT','MOVING','STANDBY','FAULT','INIT','RUNNING','ALARM','DISABLE','UNKNOWN');

	$pretimer = !isset($_REQUEST['no_pretimer']);
	$posttimer = !isset($_REQUEST['no_posttimer']);

	$db = mysqli_connect(HOST, USERNAME, PASSWORD);
	mysqli_select_db($db, DB);

	$now = time();
	
	// ----------------------------------------------------------------
	// Quote variable to make safe
	function quote_smart($value)
	{
		global $db;
		// Stripslashes
		if (get_magic_quotes_gpc()) {
			$value = stripslashes($value);
		}
		strtr($value, '&#147;&#148;`', '""'."'");
		// Quote if not integer
		if (!is_numeric($value)) {
			$value = "'".mysqli_real_escape_string($db, $value)."'";
		}
		return $value;
	}

	// ----------------------------------------------------------------
	// debug a variable
	function debug($var, $name='')
	{
		if ($name !== '') {
			echo "\$$name: ";
		}
		if (is_array($var)) {
			echo "<pre>"; print_r($var); echo "</pre><p>\n";
		}
		else {
			echo ($var===0? "0": $var)."<p>\n";
		}
	}

	// ----------------------------------------------------------------
	// parse and detect time periods
	function parse_time($time) {
		if (strpos($time, 'last ')!== false) {
			$last = explode(' ', $time);
			$i = $n = 1;
			if (count($last) == 3) {
				$i = 2;
				$n = $last[1];
			}
			if (strpos($last[$i], "second")!==false) {
				$time_factor = 1;
			}
			else if (strpos($last[$i], "minute")!==false) {
				$time_factor = 60;
			}
			else if (strpos($last[$i], "hour")!==false) {
				$time_factor = 3600;
			}
			else if (strpos($last[$i], "day")!==false) {
				$time_factor = 86400;
			}
			else if (strpos($last[$i], "week")!==false) {
				$time_factor = 604800;
			}
			else if (strpos($last[$i], "month")!==false) {
				$time_factor = 2592000; // 30days
			}
			else if (strpos($last[$i], "year")!==false) {
				$time_factor = 31536000; // 365days
			}
			$t = time();
			return date('Y-m-d H:i:s', $t - $n*$time_factor - ($t % $time_factor));
		}
		return $time;
	}

	if (!isset($_REQUEST['start'])) die('no start (date/time) selected');
	if (!isset($_REQUEST['ts'])) die('no ts (time series) selected');
	$start = explode(';', $_REQUEST['start']);
	foreach ($start as $k=>$val) {
		$start[$k] = parse_time($val);
		$stop[$k] = ' AND data_time <= NOW() + INTERVAL 2 HOUR';
		$stop_timestamp[$k] = $now;
	}
	if (isset($_REQUEST["stop"]) and strlen($_REQUEST["stop"])) {
		$stop = explode(';', $_REQUEST['stop']);
		foreach ($stop as $k=>$val) {
			$time = parse_time($val);
			$stop[$k] = strlen($val)? " AND data_time < '$time'": ' AND data_time <= NOW() + INTERVAL 2 HOUR';
			$stop_timestamp[$k] = strlen($val)? strtotime($time): $now;
		}
	}

	$ts_array = explode(';', $_REQUEST["ts"]);
	$ts = array(1=>array(),2=>array(),3=>array(),4=>array(),5=>array(),6=>array(),7=>array(),8=>array(),9=>array(),10=>array());
	foreach ($ts_array as $ts_element) {
		$t = explode(',', $ts_element);
		$x = (isset($t[1]) and is_numeric($t[1]))? $t[1]: 1;
		$y = isset($t[2])? $t[2]: ($t[1]=='multi'? 'multi': 1);
		$ts[$x][] = array($t[0], $y);
	}

	$data_type_result = array(
		"ro"=>"value_r AS val",
		"rw"=>"value_r AS val, value_w AS val_w",
		"wo"=>"value_w AS val_w"
	);
	$timezone_offset = 0;
	$big_data = array();
	$ts_counter = 0;
	$querytime = $fetchtime = 0.0;
	$samples = 0;
	$decimation = isset($_REQUEST['decimation'])? $_REQUEST['decimation']: 'maxmin';
	foreach ($ts as $xaxis=>$ts_array) {
		if (empty($ts_array)) continue;
		$start_timestamp = strtotime($start[$xaxis-1]);
		$interval = $stop_timestamp[$xaxis-1] - $start_timestamp;
		$slot_maxmin = $interval*2/1000;
		foreach ($ts_array as $ts_num=>$ts_id_num) {
			if (isset($_REQUEST['debug'])) debug($ts_num, 'ts_num');
			if (isset($_REQUEST['debug'])) debug($ts_id_num, 'ts_id_num');
			$big_data_w = array();
			list($att_conf_id,$element_index,$trash) = explode('[',trim($ts_id_num[0], ']').'[[',3);
			if (isset($_REQUEST['debug'])) debug($att_conf_id, 'att_conf_id');
			if (isset($_REQUEST['debug'])) debug($element_index, 'element_index');
			$res = mysqli_query($db, "SELECT * FROM att_conf,att_conf_data_type WHERE att_conf_id=$att_conf_id AND att_conf.att_conf_data_type_id=att_conf_data_type.att_conf_data_type_id");
			$conf_row = mysqli_fetch_array($res, MYSQLI_ASSOC);
			list($dim, $type, $io) = explode('_', $conf_row['data_type']);
			$table = sprintf("att_{$dim}_{$type}_{$io}");
			// do not process read/write for array
			if ($dim=='array') $_REQUEST['readonly'] = true;
			if (($io=="rw") and (($element_index=='0') or (!empty($_REQUEST['readonly'])))) $io = "ro";
			if (($io=="rw") and ($element_index=='1')) $io = "wo";
			$col_name = $dim=='array'? "value_r AS val,idx": $data_type_result[$io];
			if ($type=='devstate') $big_data[$ts_counter]['categories'] = $state;
			$big_data[$ts_counter]['ts_id'] = $att_conf_id;
			$big_data[$ts_counter]['label'] = strtr($conf_row['att_name'], $skipdomain);
			$big_data[$ts_counter]['xaxis'] = $xaxis;
			$big_data[$ts_counter]['yaxis'] = $ts_id_num[1];
			if ($io=="rw") {
				$big_data[$ts_counter+1]['ts_id'] = $att_conf_id;
				$big_data[$ts_counter+1]['label'] = strtr($conf_row['att_name'], $skipdomain).'_w';
				$big_data[$ts_counter]['label'] = strtr($conf_row['att_name'], $skipdomain).'_r';
				$big_data[$ts_counter+1]['xaxis'] = $xaxis;
				$big_data[$ts_counter+1]['yaxis'] = $ts_id_num[1];
			}
			$orderby = $dim=='array'? "time,idx": "data_time";
			$query = "SELECT UNIX_TIMESTAMP(data_time) AS time, $col_name FROM $table WHERE att_conf_id=$att_conf_id AND data_time > '{$start[$xaxis-1]}'{$stop[$xaxis-1]} ORDER BY $orderby";
			if (isset($_REQUEST['debug'])) debug($query);
			// debug($query); exit(0);
			$querytime -= microtime(true);
			$res = mysqli_query($db, $query);
			$querytime += microtime(true);
			$fetchtime -= microtime(true);
			$samples += mysqli_num_rows($res);
			$sample = -1;
			// limit to less than 1000 samples http://api.highcharts.com/highcharts#plotOptions.series.turboThreshold
			$sampling_every = ceil(mysqli_num_rows($res)/1000);
			$oversampled = $sampling_every>1;
			$max = $min = array();
			// if (($dim=='array') && ($big_data[$ts_counter]['yaxis']=='multi')) {
			if ($dim=='array') {
				$pretimer = false;
				$posttimer = false;
			}
			if ($pretimer and (mysqli_num_rows($res)<500)) {
				$query = "SELECT UNIX_TIMESTAMP(data_time) AS time, $col_name FROM $table WHERE att_conf_id=$att_conf_id AND data_time <= '{$start[$xaxis-1]}' ORDER BY data_time DESC LIMIT 1";
				$fetchtime += microtime(true);
				$querytime -= microtime(true);
				$res2 = mysqli_query($db, $query);
				$querytime += microtime(true);
				$fetchtime -= microtime(true);
				if (mysqli_num_rows($res2)>0) {
					$conf_row = mysqli_fetch_array($res2, MYSQLI_ASSOC);
					if (round($conf_row['time'])<strtotime($start[$xaxis-1]." $timezone"))
					$big_data[$ts_counter]['data'][] = array('x'=>strtotime($start[$xaxis-1]." $timezone")*1000,'y'=>$conf_row['val']-0, 
					'marker'=>array('symbol'=>'url(http://fcsproxy.elettra.trieste.it/docs/egiga2m/img/prestart.png)'), 
					'prestart'=>$conf_row['time']*1000); 
				}
			}
			if (($dim=='array') && ($big_data[$ts_counter]['yaxis']!='multi')) {
				$buf = array(); $oldtime = false;
				while ($row = mysqli_fetch_array($res, MYSQLI_ASSOC)) {
					if ($oldtime != $row['time']) {
						if (count($buf)) $big_data[$ts_counter]['data'][] = array($oldtime*1000, $buf);
						$buf = array();
						$oldtime = $row['time'];
					}
					$buf[] = (($type=='string') or ($row['val'] === NULL))? $row['val']: $row['val']-0;
				}
			}
			else while ($row = mysqli_fetch_array($res, MYSQLI_ASSOC)) {
				if ($oversampled) {
					if (isset($_REQUEST['debug'])) debug($row, "oversampled, decimation: $decimation, row");
					if ($decimation=='downsample') {
						if ($sampling_every>1) {
							$sample++;
							if ($sample % $sampling_every) continue;
						}
						if ($dim=='array') {
							if ($big_data[$ts_counter]['yaxis']=='multi') {
								$k = $row['idx'];
								$big_data[$ts_counter+$k]['ts_id'] = $ts_id_num[0];
								$big_data[$ts_counter+$k]['label'] = strtr($conf_row['att_name'], $skipdomain)."[$k]";
								$big_data[$ts_counter+$k]['xaxis'] = $xaxis;
								$big_data[$ts_counter+$k]['yaxis'] = $ts_id_num[1];
								$big_data[$ts_counter+$k]['data'][] = array($row['time']*1000, (($type=='string') or ($row['val'] === NULL))? $row['val']: $row['val']-0);
							}
							else {
								foreach ($v as $k=>$i) $v[$k] = $i-0; 
								$big_data[$ts_counter]['data'][] = array($row['time']*1000, $v);
							}
						}
						else {
							$big_data[$ts_counter]['data'][] = array($row['time']*1000, (($type=='string') or ($row['val'] === NULL))? $row['val']: $row['val']-0);
							if ($io=="rw") {
								$big_data_w[] = array($row['time']*1000, (($type=='string') or ($row['val'] === NULL))? $row['val']: $row['val']-0);
							}
						}
					}
					else if ($decimation=='maxmin') {
						if ($dim=='array') {
							$k = $row['idx'];
							$big_data[$ts_counter+$k]['ts_id'] = $ts_id_num[0];
							$big_data[$ts_counter+$k]['label'] = strtr($conf_row['att_name'], $skipdomain)."[$k]";
							$big_data[$ts_counter+$k]['xaxis'] = $xaxis;
							$big_data[$ts_counter+$k]['yaxis'] = $ts_id_num[1];
							$big_data[$ts_counter+$k]['data'][] = array($row['time']*1000, (($type=='string') or ($row['val'] === NULL))? $row['val']: $row['val']-0);
						}
						else {
							$slot = floor(($row['time']-$start_timestamp)/$slot_maxmin);
							if (isset($max[$slot]) and is_null($max[$slot][1])) continue;
							if (isset($_REQUEST['debug']) and (is_null($row['val']))) debug($row, gettype($row['val']));
							$v = $row['val']-0;
							if (isset($max[$slot])) {
								if ($v>$max[$slot][1]) $max[$slot] = array($row['time']*1000, $v);
								if ($v<$min[$slot][1]) $min[$slot] = array($row['time']*1000, $v);
							}
							else $max[$slot] = $min[$slot] = array($row['time']*1000, $v);
							if (is_null($row['val'])) $max[$slot] = $min[$slot] = array($row['time']*1000, NULL);
						}
					}
				}
				else {
					if (isset($_REQUEST['debug'])) debug($row, 'row');
					if ($dim=='array') {
						if ($big_data[$ts_counter]['yaxis']=='multi') {
							$k = $row['idx'];
							$big_data[$ts_counter+$k]['ts_id'] = $ts_id_num[0];
							$big_data[$ts_counter+$k]['label'] = strtr($conf_row['att_name'], $skipdomain)."[$k]";
							$big_data[$ts_counter+$k]['xaxis'] = $xaxis;
							$big_data[$ts_counter+$k]['yaxis'] = $ts_id_num[1];
							$big_data[$ts_counter+$k]['data'][] = array($row['time']*1000, (($type=='string') or ($row['val'] === NULL))? $row['val']: $row['val']-0);
						}
						else {
							foreach ($v as $k=>$i) $v[$k] = $i-0; 
							$big_data[$ts_counter]['data'][] = array($row['time']*1000, $v);
						}
					}
					else {
						$big_data[$ts_counter]['data'][] = array($row['time']*1000, (($type=='string') or ($row['val'] === NULL))? $row['val']: $row['val']-0);
						if ($io=="rw") {
							$big_data_w[] = array($row['time']*1000, (($type=='string') or ($row['val_w'] === NULL))? $row['val_w']: $row['val_w']-0);
						}
					}
				}
			}
			if ($decimation=='maxmin') {
				if (isset($_REQUEST['debug'])) debug($max, 'max');
				foreach ($max as $slot=>$point) {
					if (is_null($point[1])) {
						$big_data[$ts_counter]['data'][] = $point;
					}
					else if ($point[0]<$min[$slot][0]) {
						$big_data[$ts_counter]['data'][] = $point;
						$big_data[$ts_counter]['data'][] = $min[$slot];
					}
					else {
						$big_data[$ts_counter]['data'][] = $min[$slot];
						$big_data[$ts_counter]['data'][] = $point;
					}
				}
			}
			if ($io=="rw") {
				$ts_counter++;
				$big_data[$ts_counter]['data'] = $big_data_w;
			}
			// debug(count($big_data["ts{$xaxis}_".$ts_id_num]['data']), 'ts'.$ts_id_num);
			$ts_counter++;
			$fetchtime += microtime(true);
		}
	}

	if (defined('LOG_REQUEST')) {
		$requests = $sep = '';
		foreach ($_REQUEST as $key => $value ) {
			$requests .= $sep . $key . '=' . $value;
			$sep = '&';
		}
		$remote = $_SERVER['REMOTE_ADDR'];
		$forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR'])? $_SERVER['HTTP_X_FORWARDED_FOR']: 0;
		$fd = fopen(LOG_REQUEST, 'a');
		$date = date("Y-m-d H:i:s");
		fwrite($fd, "$date $remote $forwarded $requests query: ".round($querytime,2)."[s] fetch: ".round($fetchtime,2)."[s] #samples: $samples\n");
		fclose($fd);
	}

	//
	// EVENTS
	//
	$event = array('error', 'alarm', 'command', 'button');
	$show = array();
	foreach ($event as $e) {
		$show[$e] = (!SKIP_EVENT) || (isset($_REQUEST["show_$e"]));
	}
	if (isset($_REQUEST['debug'])) debug($show, 'show');
	if (count($show)) {
		foreach ($big_data[0]['data'] as $d) {
			if ($d[1] !== NULL) {
				$y = $d[1];
				break;
			}
		}
	}

	$big_data = array('ts'=>$big_data);

	//
	// extract ERRORs
	//
	if ($show['error']) {
		$messages = array();
		$ts_counter = 0;
		foreach ($ts as $xaxis=>$ts_array) {
			if (empty($ts_array)) continue;
			$interval = $stop_timestamp[$xaxis-1] - strtotime($start[$xaxis-1]);
			$slot_maxmin = $interval*2/1000;
			foreach ($ts_array as $ts_num=>$ts_id_num) {
				list($att_conf_id,$element_index,$trash) = explode('[',trim($ts_id_num[0], ']').'[[',3);
				$res = mysqli_query($db, "SELECT * FROM att_conf,att_conf_data_type WHERE att_conf_id=$att_conf_id AND att_conf.att_conf_data_type_id=att_conf_data_type.att_conf_data_type_id");
				$row = mysqli_fetch_array($res, MYSQLI_ASSOC);
				list($dim, $type, $io) = explode('_', $row['data_type']);
				$table = sprintf("att_{$dim}_{$type}_{$io}");
				$col_name = 'error_desc';
				$orderby = $dim=='array'? "time,idx": "data_time";
				$filter = (strlen($_REQUEST["show_error"]) and ($_REQUEST["show_error"]!=='1'))? "AND $col_name LIKE ".quote_smart(strtr($_REQUEST["show_error"], array('*'=>'%'))): ""; 
				// http://fcsproxy.elettra.trieste.it/docs/egiga2m/lib/service/hdbpp_plot_service.php?conf=fermi&start=2016-08-20%2000:00:00&stop=2016-08-30%2000:00:00&ts=8&show_error=1
				$query = "SELECT UNIX_TIMESTAMP(data_time) AS time, att_error_desc.$col_name FROM $table, att_error_desc WHERE {$table}.att_error_desc_id=att_error_desc.att_error_desc_id $filter AND att_conf_id=$att_conf_id AND data_time > '{$start[$xaxis-1]}'{$stop[$xaxis-1]} ORDER BY $orderby";
				if (isset($_REQUEST['errdebug'])) echo "$query;<br>\n";
				// $query = "SELECT UNIX_TIMESTAMP(data_time) AS time, $col_name FROM $table WHERE $filter AND att_conf_id=$att_conf_id AND data_time > '{$start[$xaxis-1]}'{$stop[$xaxis-1]} ORDER BY $orderby";
				if (isset($_REQUEST['errdebug'])) echo "$query;<br>\n";
				if (isset($_REQUEST['debug'])) debug($query);
				$querytime -= microtime(true);
				$res = mysqli_query($db, $query);
				$querytime += microtime(true);
				$fetchtime -= microtime(true);
				$samples += mysqli_num_rows($res);
				$sample = -1;
				while ($row = mysqli_fetch_array($res, MYSQLI_ASSOC)) {
					if (($msg = array_search($row[$col_name], $messages)) === false) {
						$messages[] = $row[$col_name];
						$msg = count($messages) - 1;
					}
					$data[$row['time']*100000+$ts_counter] = array('x'=>$row['time']*1000, 'y'=>$y, 'message'=>$msg, 'ts'=>$ts_counter,'marker'=>array('symbol'=>"url($host/img/event_error.png)"));
				}
				$ts_counter++;
				$fetchtime += microtime(true);
			}
		}
		if (!empty($data)) {
			ksort($data);
			$big_data['event']['error']['message'] = $messages;
			$big_data['event']['error']['data'] = array_values($data);
		}
	}



	//
	// extract ALARMs
	//
	if ($show['alarm']) {
		$data = array();
		$messages = array();
		// $db = mysqli_connect('srv-db-srf', 'alarm', 'FermiAlarm2009');
		// mysql -h srv-db-srf -u alarm-client alarm
		$db = mysqli_connect(($_REQUEST['conf']=='fermi'? 'srv-db-srf': 'ecsproxy'), 'alarm-client', '');
		mysqli_select_db($db, 'alarm');
		$stop_cond = strlen($stop_timestamp[0]) ? " AND alarms.time_sec < {$stop_timestamp[0]}": '';
		$col_name = 'description.name';
		$condition = quote_smart(strtr($_REQUEST["show_alarm"], array('*'=>'%')));
		$filter = (strlen($_REQUEST["show_alarm"]) and ($_REQUEST["show_alarm"]!=='1'))? "((description.name LIKE $condition) OR (description.msg LIKE $condition))": "NOT ISNULL($col_name)"; 
		$query = "SELECT alarms.time_sec*1000+ROUND(alarms.time_usec/1000) AS t,description.name,description.msg FROM alarms,description WHERE $filter AND alarms.id_description=description.id_description AND alarms.time_sec >= UNIX_TIMESTAMP('{$start[0]}')$stop_cond AND status='ALARM' AND ack='NACK' ORDER BY t";
		if (isset($_REQUEST['debug'])) debug($query);
		$res = mysqli_query($db, $query);
		while ($row = mysqli_fetch_array($res, MYSQLI_ASSOC)) {
			$text = "{$row['name']}<br>{$row['msg']}";
			if (($msg = array_search($text, $messages)) === false) {
				$messages[] = $text;
				$msg = count($messages) - 1;
			}
			$data[$row['t']] = array('x'=>$row['t']-0, 'y'=>$y, 'message'=>$msg, 'marker'=>array('symbol'=>"url($host/img/event_alarm.png)"));
		}
		if (!empty($data)) {
			ksort($data);
			$big_data['event']['alarm']['message'] = $messages;
			$big_data['event']['alarm']['data'] = array_values($data);
		}
		// debug($query);debug($row);exit();
		/*
		$big_data['alarm']['message'][0] = 'Fault Conditioning Mod. 7';
		$big_data['alarm']['message'][1] = "Situazione anomala sul modulatore 3: trigger thyratron assente e klystron filament 100%. Se permane l'anomalia fra 15 minuti verra' spenta l'alta tensione e i filamenti klystron saranno posti al 80%";
		$big_data['alarm']['message'][2] = 'BC01: radiation alarm ';
		$big_data['alarm']['data'][0] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 4.1) * 1000, 'y'=>$y, 'message'=>0,'marker'=>array('symbol'=>"url($host/img/event_alarm.png)"));
		$big_data['alarm']['data'][1] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 2.8) * 1000, 'y'=>$y, 'message'=>1,'marker'=>array('symbol'=>"url($host/img/event_alarm.png)"));
		$big_data['alarm']['data'][2] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 2.1) * 1000, 'y'=>$y, 'message'=>2,'marker'=>array('symbol'=>"url($host/img/event_alarm.png)"));
		$big_data['alarm']['data'][3] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 1.2) * 1000, 'y'=>$y, 'message'=>1,'marker'=>array('symbol'=>"url($host/img/event_alarm.png)"));
		*/
	}
	/*
	if ($show['command']) {
		$big_data['command']['message'][0] = 'f/modulators/modulators=>ControlledAccess';
		$big_data['command']['message'][1] = "kg02/mod/hv=>Off";
		$big_data['command']['message'][2] = 'kg07/mod/modcond-kg07-01=>On';
		$big_data['command']['data'][0] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 7.1) * 1000, 'y'=>$y, 'message'=>0,'marker'=>array('symbol'=>"url($host/img/event_command.png)"));
		$big_data['command']['data'][1] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 3.8) * 1000, 'y'=>$y, 'message'=>1,'marker'=>array('symbol'=>"url($host/img/event_command.png)"));
		$big_data['command']['data'][2] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 2.5) * 1000, 'y'=>$y, 'message'=>2,'marker'=>array('symbol'=>"url($host/img/event_command.png)"));
		$big_data['command']['data'][3] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 1.05) * 1000, 'y'=>$y, 'message'=>1,'marker'=>array('symbol'=>"url($host/img/event_command.png)"));
	}
	if ($show['button']) {
		$big_data['button']['message'][0] = 'modmulti - SwitchToControlledAccess';
		$big_data['button']['message'][1] = "kg02 conditioning - Off";
		$big_data['button']['message'][2] = 'kg07 control - On';
		$big_data['button']['data'][0] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 7.1) * 1000, 'y'=>$y, 'message'=>0,'marker'=>array('symbol'=>"url($host/img/event_button.png)"));
		$big_data['button']['data'][1] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 3.8) * 1000, 'y'=>$y, 'message'=>1,'marker'=>array('symbol'=>"url($host/img/event_button.png)"));
		$big_data['button']['data'][2] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 2.5) * 1000, 'y'=>$y, 'message'=>2,'marker'=>array('symbol'=>"url($host/img/event_button.png)"));
		$big_data['button']['data'][3] = array('x'=>(strtotime($start[0]) + ($stop_timestamp[0] - strtotime($start[0])) / 1.05) * 1000, 'y'=>$y, 'message'=>1,'marker'=>array('symbol'=>"url($host/img/event_button.png)"));
	}
	*/
	// debug($query); exit(0);
	header("Content-Type: application/json");
	echo json_encode($big_data);
?>