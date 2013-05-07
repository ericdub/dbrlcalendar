<?php
/****************************************************
 * API to search legacy program database. Results can be
 * sent in 4 different formats:
 * 1. JSON - used with JQuery/AJAX
 * 2. HTML-LIST - unordered list suitable for sidebar
 * 3. HTML - normal ouput on a page
 * 4. CSV - export to Google Calendar
 *
 * This API may be called directly using $_GET or as an
 * include using $params array. Either are strictly filtered
 * and validated for security. The DB connection is with
 * a DB user with minimal (read-only) privileges.
 *
 * Requirements:
 * - connect.php - DB connection helper
 * - common.php - common functions used with many projects
 *      (can be identified with "c_" prepended to function name)
 *
 * Created: September 2008
 * Author: Nathan Pauley
 * Revisions:
 *  	11/10/2008 - np - added HTML output format
 * 	  4/2/2009 - np - added CSV output format
 *		 4/10/2009 - np - minor tweaks and bug fixes
 *		 4/16/2009 - np - adjusted CSV output to include all program sessions
 *     4/29/2009 - np - searches with no results repeat search w/o future-only
 *								restriction - useful for twitter postings
 *     12/18/2009 - np - added Google Calendar URLS
 *      4/23/2010 - np - added "featured" to JSON output
 *      7/26/2010 - np - added support for JSONP calls
 *      8/23/2010 - np - added option to omit regional (ARG) events as reqested by Ms. J. McDonald
 *      1/25/2011 - np - added year to JSON output
 *		  2/16/2011 - np - added event title to title attribute in html-list format
 *****************************************************/

//ini_set('display_errors',TRUE);
//error_reporting(E_ALL);

define('DATABASE','pdb');
require_once ('/home/www/www.dbrl.org/connect.php');
require_once ('common/common.php');
/*

/*require_once('FirePHPCore/FirePHP.class.php');
//ob_start();
$fp = FirePHP::getInstance(true);
if ($_SERVER['REMOTE_ADDR'] != '10.2.1.14') {
	$fp->setEnabled(false);
}*/

// set initial limits
define('CAL_START','2013-02-01');
define('CAL_END','2013-06-30');
define('RESULTS_LIMIT', 10);

# ----------------------------------------------------------------------------
#
# Converts MySQL time to HH:MM a.m./p.m.
#
function time2str ($t) {

	if ($t == '00:00:00' OR $t == '') {
		return '';
	}

	list($h,$m,$s) = explode(":",$t);

	$ampm = ($h >= 12) ? "p.m." : "a.m.";

	// if between 12 - 1 p.m.
    if ($h % 12 == 0) {
        return ($m > 0) ? "12:" . $m . " " . $ampm : "Noon";
	 } else {
			return ($m > 0) ? $h % 12 . ":" . $m . " " . $ampm : $h % 12 . " " . $ampm;
	 }

} // END time2str()

# ----------------------------------------------------------------------------
#
# Converts MySQL time to HH:MM AM/PM. (Special case required by Google Calendar)
#
function times2str_ampm ($t) {

	if ($t == '00:00:00' OR $t == '') {
		return $t;
	}

	list($h,$m,$s) = explode(":",$t);

	$ampm = ($h >= 12) ? "PM" : "AM";

	$h = ($h > 12) ? $h - 12 : $h;

	return "{$h}:{$m}:{$s} {$ampm}";

} //END times2str_ampm()

# ----------------------------------------------------------------------------
#
# converts 2 MySQL times to HH:MM a.m./p.m.
#
function times2str ($t1,$t2) {

	if ($t1 == '00:00:00' OR $t1 == '')
		return '';

	// no end time
	if ($t2 == '00:00:00' OR $t2 == '')
		return time2str($t1);

	list($h1,$m1,$s1) = explode(":",$t1);
	list($h2,$m2,$s2) = explode(":",$t2);

	$ampm1 = ($h1 >= 12) ? " p.m." : " a.m.";
	$ampm2 = ($h2 >= 12) ? " p.m." : " a.m.";

	$ampm1 = ($ampm1 != $ampm2) ? $ampm1 : "";


	// if time1 is 12:xx p.m.
	if ($h1 == "12") {
		$time_str = ($m1 > 0) ? "12:" . $m1 . $ampm1 : "Noon";
	} else {
		$time_str = ($m1 > 0) ? $h1 % 12 . ":" . $m1 . $ampm1 : $h1 % 12 . $ampm1;
	}
	$time_str .= "-";

	if ($h2 == "12") {
		$time_str .= ($m2 > 0) ? "12:" . $m2 . $ampm2 : "Noon";
	} else {
		$time_str .= ($m2 > 0) ? $h2 % 12 . ":" . $m2 . $ampm2 : $h2 % 12 . $ampm2;
	}

	return ($time_str);

}  //END times2str()



// ================================================================================

// make sure we have a connection
if (isset($mysqli)) {

	// define various name hashes
	$locations_short = array(
		'ARG' => 'Regional',
		'COL' => 'Columbia',
		'CAL' => 'Fulton',
		'SBC' => 'Ashland',
		'BKM' => 'Outreach/Bookmobile',
		'BMP' => 'Outreach/Bookmobile',
		'OTR' => 'Outreach/Bookmobile',
		' ' => ''
	);
	$locations_long = array(
		'ARG' => 'Regional',
		'COL' => 'Columbia Public Library',
		'CAL' => 'Callaway County Public Library',
		'SBC' => 'Southern Boone County Public Library',
		'BKM' => 'Bookmobile',
		'BMP' => 'Bookmobile',
		'OTR' => 'Outreach',
		' ' => ''
	);
	$locations_address = array(
		'ARG' => 'Regional',
		'COL' => 'Columbia Public Library, 100 W. Broadway, Columbia, MO',
		'CAL' => 'Callaway County Public Library, 710 Court St., Fulton, MO',
		'SBC' => 'Southern Boone County Public Library, 117 E. Broadway, Ashland, MO',
		'BKM' => 'Bookmobile',
		'BMP' => 'Bookmobile',
		'OTR' => 'Outreach',
		' ' => ''
	);

	// $_GET call is default method

	// validate/filter passed parameters
	$format   = ( isset($_GET['f']) && in_array($_GET['f'], array('json','html','list','csv')) ) ? $_GET['f'] : 'html';
	$year     = ( isset($_GET['y']) && is_numeric($_GET['y']) ) ? $_GET['y'] : date('Y');
	$month    = ( isset($_GET['m']) && is_numeric($_GET['m']) ) ? sprintf("%02d",$_GET['m']) : NULL ;
	$day      = ( isset($_GET['d']) && is_numeric($_GET['d']) ) ? sprintf("%02d",$_GET['d']) : NULL ;
	$location = ( isset($_GET['w']) && array_key_exists(strtoupper($_GET['w']), $locations_short) ) ? strtoupper($_GET['w']) : '' ;
	$type	  = ( isset($_GET['e']) && strlen($_GET['e']) == 1 ) ? strtoupper($_GET['e']) : '';
	$audience = ( isset($_GET['a']) && strlen($_GET['a']) == 1 ) ? strtoupper($_GET['a']) : '';
	$q 		  = ( isset($_GET['s']) && !c_suspicious_sql(array($_GET['s'])) ) ? $mysqli->real_escape_string($_GET['s']) : '';
	$tag	  = ( isset($_GET['t']) && !c_suspicious_sql(array($_GET['t'])) ) ? $mysqli->real_escape_string($_GET['t']) : '';
	$program_id= ( isset($_GET['i']) && is_numeric($_GET['i']) ) ? $_GET['i'] : NULL;

	$limit    = ( isset($_GET['l']) && is_numeric($_GET['l']) ) ? $_GET['l'] : RESULTS_LIMIT;
	$page     = ( isset($_GET['p']) && is_numeric($_GET['p']) ) ? $_GET['p'] : 0;
	$upcoming = ( isset($_GET['u']) && $_GET['u'] == 1) ? true : false ;
	$featured = ( isset($_GET['z']) && $_GET['z'] == 1) ? true : false ;
	$callback = ( isset($_GET['callback']) ) ? $_GET['callback'] : NULL;   //used in JSONP calls
	$omit_regional = ( isset($_GET['o']) && $_GET['o'] == 1) ? true : false;

	// To allow including this API, parameters are passed by $params array
	if ( isset($params) ) {
			$format   = ( isset($params['f']) ) ? $params['f'] : 'html';
			$year     = ( isset($params['y']) ) ? $params['y'] : date('Y');
			$month    = ( isset($params['m']) ) ? sprintf("%02d",$params['m']) : NULL;
			$day      = ( isset($params['d']) ) ? sprintf("%02d",$params['d']) : NULL;
			$location = ( isset($params['w']) ) ? strtoupper($params['w']) : '' ;
			$type	  = ( isset($params['e']) ) ? strtoupper($params['e']) : '';
			$audience = ( isset($params['a']) ) ? strtoupper($params['a']) : '';
			$q 		  = ( isset($params['s']) ) ? $mysqli->real_escape_string($params['s']) : '';
			$tag	  = ( isset($params['t']) ) ? $mysqli->real_escape_string($params['t']) : '';
			$program_id= ( isset($params['i']) ) ? $params['i'] : NULL;

			$limit    = ( isset($params['l']) ) ? $params['l'] : RESULTS_LIMIT;
			$page     = ( isset($params['p']) ) ? $params['p'] : 0;
			$upcoming = ( isset($params['u']) ) ? true : false ;
			$featured = ( isset($params['z']) ) ? true : false ;
	}


	// constuct the query
	$sql_select = "SELECT C.ID, Featured, Title, Date,
	DATE_FORMAT(Date, '%W, %M %e, %Y') AS NiceDate,
	DATE_FORMAT(Date, '%W, %M %e') AS NiceDateShort,
	DATE_FORMAT(Date, '%m/%d/%Y') AS CommonDate,
	DATE_FORMAT(CONVERT_TZ(TIMESTAMP(CONCAT(Date,' ',StartTime1)),'America/Chicago','GMT'), '%Y%m%dT%H%i%SZ') AS UTCStartDate1,
	DATE_FORMAT(CONVERT_TZ(TIMESTAMP(CONCAT(Date,' ',EndTime1)),'America/Chicago','GMT'), '%Y%m%dT%H%i%SZ') AS UTCEndDate1,
	StartTime1, EndTime1,
	StartTime2, EndTime2,
	StartTime3, EndTime3,
	DATE_FORMAT(RegStartDate, '%W, %M %e') AS RegDate,
	Location,
	Type,
	Audience,
	Room,
	Canceled1, Canceled2, Canceled3,
	Notes,
	Featured,
	AgeDescription,
	LocationOverride,
	Tags,
	Description,
	ShortDescription";

	$sql_from = " FROM Calendar C INNER JOIN Event E ON C.ClassID = E.ID";


	$sql_where = " WHERE (Date >= '".CAL_START."' AND Date <= '".CAL_END."')";

	// allow KidCare events if specifically selected OR searching by ID number
	if ( $type == 'K' || !empty($program_id) ) {
		$sql_where .= " AND (E.Type IN('G','C','S','O','T','F','B','K') )";
	} else {
		$sql_where .= " AND (E.Type IN('G','C','S','O','T','F','B') )" ;
	}

	$sql_where_no_expired_events = '';

	// upcoming can span across months; otherwise limit to selected month
	if ( !isset($program_id)  ) {

		if ( isset($month) ) {
			//adjust year when searching in next calendar year
			$year = ( $month >= date('m') ) ? $year : $year + 1;

			$sql_where .= " AND (DATE_FORMAT(Date, '%Y') = {$year}) AND (DATE_FORMAT(Date, '%m') = {$month})";

			if ( isset($day) ) {

				$sql_where .= " AND (DATE_FORMAT(Date, '%d') = {$day})";

			}

		} else {

			$sql_where_no_expired_events = " AND ((Date > '".date('Y-m-d')."') OR ((Date = '". date('Y-m-d')."') AND (IF(GREATEST(EndTime1,EndTime2,EndTime3) <> '00:00:00',GREATEST(EndTime1,EndTime2,EndTime3),'23:59:59') > '". date('H:i') ."')))";
		}
	}
	/*if ( $upcoming ) {
		$sql_where .= " AND ((Date > '".date('Y-m-d')."') OR ((Date = '". date('Y-m-d')."') AND (IF(EndTime1 <> '00:00:00',EndTime1,'23:59:59') > '". date('H:i') ."')))";
	} elseif ( isset($month) || isset($year) ){
		$sql_where .= " AND (DATE_FORMAT(Date, '%Y') = {$year}) AND (DATE_FORMAT(Date, '%m') = {$month})";
	}*/

/*	if ( !empty($location) ) {
	  if ( $omit_regional ):
		if ($location == 'OTR') {
			$sql_where .= " AND (Location IN ('BKM','BMP','OTR'))";
		} else {
			$sql_where .= " AND (Location = '{$location}')";
		}
	   else:
		if ($location == 'OTR') {
			$sql_where .= " AND (Location IN ('BKM','BMP','OTR','ARG'))";
		} else {
			$sql_where .= " AND (Location = '{$location}' OR Location = 'ARG')";
		}
	   endif;
	}*/
	
	if ( !empty($location) ) {
		if ($location == 'OTR') {
			$sql_where .= " AND (Location IN ('BKM','BMP','OTR','ARG'))";
		} else {
			$sql_where .= " AND (Location = '{$location}' OR Location = 'ARG')";
		}
	}
	
	// exclude regional events
	if ( $omit_regional ) {
		$sql_where .= " AND (Location != 'ARG')";
	}
	

	if ( !empty($type) ) {
		$sql_where .= " AND (E.Type = '{$type}')";
	}
	if ( !empty($audience) ) {
		//special case: "kids" audience type should include family, preschool & elementary
		if ( $audience == 'M' ) {
			$sql_where .= " AND (E.Audience IN ('F','P','E'))";
		// special case 2: adult & 55+ events
		} elseif ( $audience == 'G' ) {
			$sql_where .= " AND (E.Audience IN ('A','S'))";
		} else {
			$sql_where .= " AND (E.Audience = '{$audience}')";
		}

	}
	if ( !empty($q) ) {
		$sql_where .= " AND ((Title LIKE '%{$q}%') OR ( Description LIKE '%{$q}%'))";
	}
	if ( !empty($tag) ) {
		//$sql_where .= " AND (Notes LIKE '%[%{$tag}%]%')";
		$sql_where .= " AND (Tags LIKE '%{$tag}%')";
	}
	if ( !empty($program_id) ) {
		$sql_where .= " AND (C.ID = '{$program_id}')";
	}
	if ( !empty($featured) ) {
		$sql_where .= " AND (C.Featured = 1) AND (C.Canceled1 = 0)";
	}


	$sql_order = " ORDER BY Date, StartTime1, Location";
	$sql_order_reverse = " ORDER BY Date DESC, StartTime1, Location";

	// get the number of matching rows without the limit
	$num_results = 0;
	if ( $result = $mysqli->query("SELECT COUNT(*) {$sql_from} {$sql_where} {$sql_where_no_expired_events} {$sql_order}") ) {
		$r = $result->fetch_array(MYSQLI_NUM);
		$num_results = $r[0];
	}

	// limit the number of results
	if ( $limit != 0 ) {
		if ( !empty($page) ) {
			$sql_limit = " LIMIT " . ($page) * $limit . ", {$limit}";
		} else {
			$sql_limit = " LIMIT {$limit}";
		}
	}
	$sql = "{$sql_select} {$sql_from} {$sql_where} {$sql_where_no_expired_events} {$sql_order} {$sql_limit}";

	// DEBUG
	//echo '<!--' . $sql . '-->';
	//$fp->log($sql, 'SQL');

	// execute query;
	$result = $mysqli->query($sql);

	// if no results, search again w/o future-only date limits
	if ( $result->num_rows == 0 ):
		//execute modified query
		
		$sql = "{$sql_select} {$sql_from} {$sql_where} {$sql_order_reverse} {$sql_limit}";
		//$fp->log($sql, 'Modified SQL');
		$result = $mysqli->query($sql);

		// find new number of matching rows without limit and without future-only date limits
		$num_results = 0;
		if ( $result_matches = $mysqli->query("SELECT COUNT(*) {$sql_from} {$sql_where} {$sql_order}") ) {
			$r = $result_matches->fetch_array(MYSQLI_NUM);
			$num_results = $r[0];
			//Text to show if there are no future events
			$message = 'The are no events currently scheduled in this category, but new ones are added all the time, so check back soon! Below are some recent programs in this category.';
		}
		//$num_results = $result->num_rows;
	endif;

	$temp_array = array();

	if ( $result->num_rows == 0 ):
		// truely nothing to show
		$html = "<p>No matching events</p>";
	else:

		// for each result, build the JSON and HTML version to be printed at the end.
    	//printf("Select returned %d rows.\n", $result->num_rows);
		$html = '<div id="calendar">';
        
		$csv_all = "Subject,Start Date,Start Time,End Date,End Time,All Day Event,Description,Location,Private\n";



		// list format
		$html_list = '<ul>';

		$date = '2000-01-01';

		$json = array('num' => $num_results);




		while ( $r = $result->fetch_object() ):

			$csv = '';

			// ignore location for events not at library
			if ( $r->LocationOverride ) {
				$r->Location = $r->Room;
				$r->Room = '';
			}

			$nice_time_str = times2str($r->StartTime1,$r->EndTime1);
			if ($r->StartTime2 != '00:00:00' AND $r->StartTime2 != '') {
				$nice_time_str .= ' <strong>or</strong> ' . times2str($r->StartTime2,$r->EndTime2);
			}
			if ($r->StartTime3 != '00:00:00' AND $r->StartTime3 != '') {
				$nice_time_str .= ' <strong>or</strong> ' . times2str($r->StartTime3,$r->EndTime3);
			}
			if ($r->StartTime4 != '00:00:00' AND $r->StartTime4 != '') {
				$nice_time_str .= ' <strong>or</strong> ' . times2str($r->StartTime4,$r->EndTime4);
			}if ($r->StartTime5 != '00:00:00' AND $r->StartTime5 != '') {
				$nice_time_str .= ' <strong>or</strong> ' . times2str($r->StartTime5,$r->EndTime5);
			}
			
			$canceled_msg = '';
			if ($r->Canceled1) { $canceled_msg .= '<strong><em>The program at ' . time2str($r->StartTime1) . ' has been canceled.</em></strong><br />'; }
			if ($r->Canceled2) { $canceled_msg .= '<strong><em>The program at ' . time2str($r->StartTime2) . ' has been canceled.</em></strong><br />'; }
			if ($r->Canceled3) { $canceled_msg .= '<strong><em>The program at ' . time2str($r->StartTime3) . ' has been canceled.</em></strong><br />'; }
			if ($r->Canceled4) { $canceled_msg .= '<strong><em>The program at ' . time2str($r->StartTime4) . ' has been canceled.</em></strong><br />'; }
			if ($r->Canceled5) { $canceled_msg .= '<strong><em>The program at ' . time2str($r->StartTime5) . ' has been canceled.</em></strong><br />'; }

			//convert Title & Description to UTF encoding
			$title = $r->Title;
			//$title = iconv("Windows-1252","UTF-8",$r->Title);

			//form Google Calendar URL
			$google_calendar_url = 'http://www.google.com/calendar/event?action=TEMPLATE&text='.htmlentities($title, ENT_QUOTES, 'UTF-8');
			$google_calendar_url .= "&dates={$r->UTCStartDate1}/{$r->UTCEndDate1}";
			$google_calendar_url .= "&location={$locations_address[$r->Location]}";
			$google_calendar_url .= "&details={$r->Room}";
			$google_calendar_url .= '&sprop=website:http://www.dbrl.org&sprop=name:Daniel Boone Regional Library';
			//$google_calendar_url = str_replace('/','%2F',$google_calendar_url);

			list($y,$m,$d) = explode('-',$r->Date);

			// store the record for later JSON encoding
			array_push($temp_array, array(
				"id" => $r->ID,
				"featured" => $r->Featured,
				"title" => htmlentities($title, ENT_QUOTES, 'UTF-8'),
				"year" => $y,
				"date" => $r->Date,
				"canceled" => array($r->Canceled1,$r->Canceled2,$r->Canceled3, $r->Canceled4, $r->Canceled5 ),
				"canceled_msg" => $canceled_msg,
				"nicedate" => $r->NiceDate,
				"nicedateshort" => $r->NiceDateShort,
				"nicetime" => $nice_time_str,
				"location_code" => strtolower($r->Location),
				"location" => ( $r->LocationOverride ) ? $r->Location : $locations_short[$r->Location],

				"location_long" => ( $r->LocationOverride ) ? $r->Location : $locations_long[$r->Location],
				"type" => $r->Type,
				"audience" => $r->Audience,
				"room" => $r->Room,
				"regdate" => $r->RegDate,
				"starttime1" => $r->StartTime1,
				"endtime1" => $r->EndTime1,
				"starttime2" => $r->StartTime2,
				"endtime2" => $r->EndTime2,
				"starttime3" => $r->StartTime3,
				"endtime3" => $r->EndTime3,
				"starttime4" => $r->StartTime4,
				"endtime4" => $r->EndTime4,
				"starttime5" => $r->StartTime5,
				"endtime5" => $r->EndTime5,
				"message" => $message,
				"googlecal" => $google_calendar_url,
				//"description" => c_insert_links(htmlentities(iconv("Windows-1252","UTF-8",preg_replace("/[\n\r]/"," ",$r->Description)),ENT_QUOTES, 'UTF-8'))
				"agedescription" => htmlentities($r->AgeDescription),
				"description" => c_insert_links(htmlentities(preg_replace("/[\n\r]/"," ",$r->Description),ENT_QUOTES, 'UTF-8'))
				));

			/*** BEGIN HTML/HTML_LIST OUTPUT ********************/

			$html .= '<div class="cal-event ' . strtolower($r->Location) . '">' . "\n";
			$html .= '<div class="cal-title">'. htmlentities($title, ENT_QUOTES, 'UTF-8') . "</div>\n";
        	$html .= '<div class="cal-date">' . $r->NiceDate;
			if (!empty($nice_time_str)) { $html .= ' &#8250; ' . $nice_time_str; }
			$html .= "</div>\n";

			$html .= '<div class="cal-room">';
			if ( $r->LocationOverride ) {
				$html .= $r->Location;
			} else {
				$html .= $locations_long[$r->Location];
			}
        	if (!empty($r->Room)) { $html .= ', '. $r->Room; }
        	$html .= "</div >\n";

			$canceled_class = (!empty($canceled_msg)) ? ' class="canceled"' : '';

			$html .= (!empty($canceled_msg)) ? '<p>&raquo; ' . $canceled_msg . '</p>' : '';

			//$html .= '<p' . $canceled_class . '>'. c_insert_links(htmlentities(iconv("Windows-1252","UTF-8",$r->Description),ENT_QUOTES, 'UTF-8'));
			$html .= '<p' . $canceled_class . '>'. c_insert_links(htmlentities($r->Description,ENT_QUOTES, 'UTF-8'));

			if (isset($r->AgeDescription)) {
						$html .= ' ' . $r->AgeDescription;
			}
			if (isset($r->RegDate)) {
						$html .= ' <span class="registration">Registration begins ' . $r->RegDate . '.</span>';
			}
			$html .= "</p>\n";

			$html .= "</div>\n";

			$title_entitied = htmlentities($title, ENT_QUOTES, 'UTF-8');

			$html_list .= '<li><a href="http://www.dbrl.org/api.calendar.php?i=' . $r->ID .'&amp;height=340&amp;width=440" title="' . $title_entitied . '" class="thickbox">' . $title_entitied . '</a><br />';
			$html_list .= '<span class="date"';
			$html_list .= (!empty($canceled_msg)) ? ' style="text-decoration:line-through"' : '';
			if ( $r->Type == 'T' )  {
				$html_list .= '>' . $r->NiceDateShort . ' &#8250; ' . $r->Room . '</span></li>';
			} else {
				$html_list .= '>' . $r->NiceDateShort . ' &#8250; ' . $locations_short[$r->Location] . '</span></li>';
			}


			/*** BEGIN CSV OUTPUT **********************/

	      // very hacky way of dealing with legacy character encoding issues in PDB
			// find printer and regular double quotes
			$quotes = array('"','“',"”");

			//"Subject,Start Date,Start Time,End Date,End Time,All Day Event,Description,Age Description, Location,Private\n";
			$csv .= '"' . str_replace($quotes,'""',$title) . '",' . $r->CommonDate . ',';


			// set a date/time placeholder string pattern
			$csv .= '%%%';
			$csv_datetime = array();

			// Need to store date/time strings for all possible sessions (max 3)
			if ( $r->StartTime1 == '00:00:00' ) {
				$csv_datetime[] = ',,,True,'; 			//no start time, no end date/time, all day event
			} else {
				$csv_datetime[] = times2str_ampm($r->StartTime1) . ',' . $r->CommonDate . ',' . times2str_ampm($r->EndTime1) . ',False,';
			}
			if ( $r->StartTime2 != '00:00:00' AND $r->StartTime2 != '' ) {
				$csv_datetime[] = times2str_ampm($r->StartTime2) . ',' . $r->CommonDate . ',' . times2str_ampm($r->EndTime2) . ',False,';
			}
			if ( $r->StartTime3 != '00:00:00' AND $r->StartTime3 != '' ) {
				$csv_datetime[] = times2str_ampm($r->StartTime3) . ',' . $r->CommonDate . ',' . times2str_ampm($r->EndTime3) . ',False,';
			}

			//$csv .=  '"'.iconv("Windows-1252","UTF-8",preg_replace("/[\n\r]/"," ",c_utf8_trans_unaccent($r->Description)));
			if ( isset($r->ShortDescription) && $r->ShortDescription ){
                $csv .=  '"'.preg_replace("/[\n\r]/"," ",str_replace($quotes,'""',$r->ShortDescription));
            }  else {
                $csv .=  '"'.preg_replace("/[\n\r]/"," ",str_replace($quotes,'""',$r->Description));
            }


            if (isset($r->AgeDescription) && $r->AgeDescription) {
						$csv .= ' '. $r->AgeDescription;
			}
            if (isset($r->RegDate) && $r->RegDate) {
						$csv .= ' ' . 'Registration begins ' . $r->RegDate . '.';
			}
            $csv .= '",';

            if ( $r->LocationOverride ) {
               $csv .= '"' . $r->Location . '",';
            }  else {
                $csv .= '"' . $locations_address[$r->Location] . '",';
            }

			$csv .= "False\n";

			// Each session needs to have a seperate line; a little hacky, but works.
			foreach ($csv_datetime as $c) {
				$csv_all .= str_replace('%%%', $c, $csv);
			}


		endwhile;

      $html .=  '</div>';
		$html_list .= '</ul>';

		//close DB query
    	$result->close();

	endif; // end if results to show

	$json['events'] = $temp_array;


	// Send the requested format
	switch ($format) {
		case 'csv': echo $csv_all; break;
		case 'html': echo $html; break;
		case 'list': echo $html_list; break;
		case 'json':
			if ( isset($callback) ) {
				header('Content-type: application/javascript');
				echo $callback . '('.str_replace('\/','/',json_encode($json)) . ');';
			} else {
				echo str_replace('\/','/',json_encode($json));
			}
			break;
	}

} // END if successful DB connetion

?>
