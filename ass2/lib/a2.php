<?php
// COMP3311 18s1 Assignment 2
// Functions for assignment Tasks A-E
// Written by Christian Fares (z5116082), May 2018

// assumes that defs.php has already been included

function patternBreak($pattern)
{
	
}

// Task A: get members of an academic object group

// E.g. list($type,$codes) = membersOf($db, 111899)
// Inputs:
//  $db = open database handle
//  $groupID = acad_object_group.id value
// Outputs:
//  array(GroupType,array(Codes...))
//  GroupType = "subject"|"stream"|"program"
//  Codes = acad object codes in alphabetical order
//  e.g. array("subject",array("COMP2041","COMP2911"))

function membersOf($db,$groupID)
{
	$q = "select * from acad_object_groups where id = %d";
	$group = dbOneTuple($db, mkSQL($q, $groupID));
	$q = "select * from acad_object_groups where parent = %d";
	$children = dbAllTuples($db, mkSQL($q, $groupID));
	if ($group["gdefby"] == "enumerated") {
		if ($group["gtype"] == "subject") {
			$q = "select code from joinSubjectGroupsAndCodes where ao_group = %d";
		} elseif ($group["gtype"] == "stream") { 
			$q = "select code from joinStreamGroupsAndCodes where ao_group = %d";
		} elseif ($group["gtype"] == "program") {
			$q = "select code from joinProgramGroupsAndCodes where ao_group = %d";
		}
		$codes = array_map('array_shift', dbAllTuples($db, mkSQL($q, $groupID)));
		for ($x = 0; $x < count($children); $x++) {
			$codes = array_merge($codes, array_map('array_shift', dbAllTuples($db, mkSQL($q, $children[$x]["id"]))));
		}
		return array($group['gtype'], $codes);
	} elseif ($group["gdefby"] == "pattern") {
		$definition = $group["definition"];

		$definition = preg_replace('/{|}/', '', $definition);
		$definition = preg_replace('/;/', ',', $definition);
		$patterns = preg_split('/,/', $definition);
		$q = "select code from ";
		if ($group["gtype"] == "subject") {
			$q .= 'subjects ';
		} elseif ($group["gtype"] == "stream") {
			$q .= "streams ";
		} elseif ($group["gtype"] == "program") {
			$q .= "programs ";
		}
		$q .= 'where code ';
		$codes = array();
		foreach ($patterns as $pattern) {
			if (preg_match('/^((GENG)|(GEN#)|(FREE)|(ALL)|(all)|####|(|\/)F=)/', $pattern) == 1) {
				$codes[] = $pattern;
				continue;
			}
			$pattern = preg_replace('/#|x/', '.', $pattern);
			if (preg_match('/!/', $pattern) == 1) {
				$pattern = preg_replace('/!/', '', $pattern);
				$query = $q . '!~ %s';
			} else {
				$query = $q . '~ %s';
			}
			$codes = array_merge($codes, array_map('array_shift', dbAllTuples($db, mkSQL($query, $pattern))));
		}
		sort($codes);
		return array($group['gtype'], $codes);
	}
}


// Task B: check if given object is in a group

// E.g. if (inGroup($db, "COMP3311", 111938)) ...
// Inputs:
//  $db = open database handle
//  $code = code for acad object (program,stream,subject)
//  $groupID = acad_object_group.id value
// Outputs:
//  true/false

function checkIfFaculty($db, $type, $code, $pattern)
{
	$matches = array();
	$retval = true;
	if (preg_match('/F=(.*)/', $pattern, $matches)) {
		$unswid = preg_replace('/!/', '', $matches[1]); # remove ! if found. in the case unswid != matches[1], otherwise they're the same
		if ($type == 'subject') {
			$q = 'select offeredby from subjects where code = %s';
		} elseif ($type == 'stream') {
			$q = 'select offeredby from streams where code = %s';
		} elseif ($type == 'program') {
			$q = 'select offeredby from programs where code = %s';
		}
		$t = dbOneTuple($db, mkSQL($q, $code)); #check if code offered by the specified faculty
		$q = 'select * from getFacultyUNSWID(%d)';
		$t = dbOneTuple($db, mkSQL($q, $t['offeredby']));
		if ($t[0] != $unswid) { # means it did not belong to faculty
			$retval = false;
		}
		if ($unswid != $matches[1]) { # means we had something like F=!SCI
			$retval = !$retval; # so if before it belonged to faculty code is not in the group. and vice versa
		}
	}
	return $retval;
}

function inGroup($db, $code, $groupID)
{
	$groupMembers = membersOf($db, $groupID);
	$type = array_shift($groupMembers);
	$groupMembers = array_shift($groupMembers);

	if (in_array($code, $groupMembers)) {
		return true;
	} else {
		foreach ($groupMembers as $pattern) {
			$matches = array();
			if (preg_match('/^((ALL)|(all))/', $pattern) and !preg_match('/^GEN/', $code)) {
				if (checkIfFaculty($db, $type, $code, $pattern)) {
					return true;
				}
			} elseif (preg_match('/^((FREE)|(####))/', $pattern) and !preg_match('/^GEN/', $code)) {
				$pattern = preg_replace('/#|x/', '.', $pattern);
				$testPattern = preg_replace('/(FREE)|\/.*/', '', $pattern);
				if (preg_match('#'. $testPattern .'#', $code)) {
					if (checkIfFaculty($db, $type, $code, $pattern)) {
						return true;
					}
				}				
			} elseif (preg_match('/^(GENG|GEN#)/', $pattern, $matches) and preg_match('/^GEN/', $code)) {
				$pattern = preg_replace('/GENG/', 'GEN#', $pattern);
				$pattern = preg_replace('/#|x/', '.', $pattern);
				$testPattern = preg_replace('/\/.*/', '', $pattern); # remove the /F=.. part if any (temporarily)
				if (preg_match('#'. $testPattern .'#', $code)) {
					if (checkIfFaculty($db, $type, $code, $pattern)) {
						return true;
					}
				}
			}
		}
	}

	return false; // stub
}


// Task C: can a subject be used to satisfy a rule

// E.g. if (canSatisfy($db, "COMP3311", 2449, $enr)) ...
// Inputs:
//  $db = open database handle
//  $code = code for acad object (program,stream,subject)
//  $ruleID = rules.id value
//  $enr = array(ProgramID,array(StreamIDs...))
// Outputs:

function canSatisfy($db, $code, $ruleID, $enrolment)
{
	$q = "select * from getGroupFromRule(%d)";
	$group = dbOneTuple($db, mkSQL($q, $ruleID));
	if ($group['gdefby'] == 'enumerated') { # if enumerated no need to worry about invalid courses
		if (inGroup($db, $code, $group['id'])) {
			return true;
		}
	} elseif ($group['gdefby'] == 'pattern') {
		if (preg_match('/^GEN/', $code)) { # if the code provided is a Gen ed check if satisfies the rule
			$stu_faculties = array(); # get all the faculties that the student belongs to
			$q = 'select facultyof(p.offeredby)
					from programs p
					where p.id = %d';
			$t = dbOneTuple($db, mkSQL($q, $enrolment[0]));
			$stu_faculties[] = $t['facultyof'];
			$q = 'select facultyof(s.offeredby)
					from streams s
					where s.id = %d';
			foreach ($enrolment[1] as $stream) {
				$t = dbOneTuple($db, mkSQL($q, $stream));
				$stu_faculties[] = $t['facultyof'];
			}
			$q = 'select facultyof(s.offeredby)
					from subjects s
					where s.code = %s';
			$t = dbOneTuple($db, mkSQL($q, $code));
			if (in_array($t['facultyof'], $stu_faculties)) { # gen ed course belongs to one of the faculties the student belongs to
				return false;
			}
		}
		if (inGroup($db, $code, $group['id'])) { # if not a GEN course or it is a Gen course student can do
			return true;
		}
	}
	return false; // stub
}

# taken from php manual from http://php.net/manual/en/function.usort.php
function cmp($a, $b)
{
    if ($a[0] == $b[0]) {
        return 0;
    }
    return ($a[0] < $b[0]) ? -1 : 1;
}

function rulesPush ($db, $array, $query, $code, $type, $mode) {
	if ($mode == 'program') {
		$qHandle = dbQuery($db, mkSQL($query, $code, $type));
		while ($tuple = dbNext($qHandle)) {
			if (end($array)['rule'] != $tuple['rule']) {
				array_push($array, array("rule" => $tuple['rule'], "min" => $tuple['min'],
									 "max" => $tuple['max'], "complete" => 0));
			}
		}
	} elseif ($mode == 'stream') {
		foreach ($code as $streamid) {
			$qHandle = dbQuery($db, mkSQL($query, $streamid, $type));
			while ($tuple = dbNext($qHandle)) {
				if (end($array)['rule'] != $tuple['rule']) {
					array_push($array, array("rule" => $tuple['rule'], "min" => $tuple['min'], 
										"max" => $tuple['max'], "complete" => 0));
				}
			}
		}
	}
	reset($array);
	return $array;
}

// Task D: determine student progress through a degree

// E.g. $vtrans = progress($db, 3012345, "05s1");
// Inputs:
//  $db = open database handle
//  $stuID = People.unswid value (i.e. unsw student id)
//  $semester = code for semester (e.g. "09s2")
// Outputs:
//  Virtual transcript array (see spec for details)

function progress($db, $stuID, $term)
{
	# get transcript
	$q = "select * from transcript(%d, %d)";
	$transcript = dbAllTuples($db, mkSQL($q, $stuID, $term));
	
	#get sem code eg 09ds2
	$q = "select * from getSemCode(%d)";
	$semCode = dbOneTuple($db, mkSQL($q, $term))[0];

	$wamElem = array_pop($transcript);
	if (!empty($transcript)) { // they enrolled during or before S
		$lastSemCode = end($transcript)[1];
		reset($transcript);
		if ($semCode != $lastSemCode) {
			$semCode = $lastSemCode;
		}
	} else { # find the fist semester in which they enrolled
		$q = "select semester from program_enrolments where student = %d order by semester limit 1";
		$semid = dbOneTuple($db, mkSQL($q, $stuID))[0];
		$q = "select * from getSemCode(%d)";
		$firstSemCode = dbOneTuple($db, mkSQL($q, $semid))[0];
		$semCode = $firstSemCode;
		$q = "select * from transcript(%d, %d)";
		$transcript = dbAllTuples($db, mkSQL($q, $stuID, $semid));
		$wamElem = array_pop($transcript);
	}

	# get sem id
	$q = "select * from getSemid(%d, %s)";
	$year = (int) "20".substr($semCode, 0, 2);
	$sem = strtoupper(substr($semCode, 2, 2));
	$semid = dbOneTuple($db, mkSQL($q, $year, $sem))[0];
	
	#get program enrolments and stream enrolement
	$q = "select id, program from program_enrolments where student = %d and semester = %d";
	$t = dbOneTuple($db, mkSQL($q, $stuID, $semid));
	$enrolID = $t[0]; # programs_enrolment id
	$program = $t[1];
	$q = "select stream from stream_enrolments where partof = %d";
	$streams = array_map('array_shift', dbAllTuples($db, mkSQL($q, $enrolID)));

	#get the rules for the program and stream
	$pq = "select pr.rule, r.min, r.max
		  from program_rules pr
		  inner join rules r on r.id = pr.rule
		  where pr.program = %d
		  and r.type = %s";
	$sq = "select sr.rule, r.min, r.max 
		from stream_rules sr
		inner join rules r on r.id = sr.rule
		where sr.stream = %d
		and r.type = %s";

	$rules = array();
	$rules = rulesPush($db, $rules, $pq, $program, 'CC', 'program');
	$rules = rulesPush($db, $rules, $sq, $streams, 'CC', 'stream');
	$rules = rulesPush($db, $rules, $pq, $program, 'PE', 'program');
	$rules = rulesPush($db, $rules, $sq, $streams, 'PE', 'stream');
	$rules = rulesPush($db, $rules, $pq, $program, 'FE', 'program');
	$rules = rulesPush($db, $rules, $sq, $streams, 'FE', 'stream');
	$rules = rulesPush($db, $rules, $pq, $program, 'GE', 'program');
	$rules = rulesPush($db, $rules, $sq, $streams, 'GE', 'stream');
	$rules = rulesPush($db, $rules, $pq, $program, 'LR', 'program');
	$rules = rulesPush($db, $rules, $sq, $streams, 'LR', 'stream');

	$ret = array();
	foreach ($transcript as $record) {
		# check if complete
		if ($record['mark'] != null) {
			#check if passed
			if ($record['uoc'] > 0) {
				#check which req it satisfies if any
				$sat = false;
				foreach ($rules as &$req) {
					if (canSatisfy($db, $record['code'], $req['rule'], array($program, $streams))) {
						if ($req['max'] >= $req['complete'] + $record['uoc']) {
							$req['complete'] =  $req['complete'] + $record['uoc'];
							array_push($ret, array($record['code'], $record['term'], $record['name'], $record['mark'],
										$record['grade'], $record['uoc'], ruleName($db, $req['rule'])));
							$sat = true;
							break;
						}
					}
				}
				if (!$sat) {
					array_push($ret, array($record['code'], $record['term'], $record['name'], $record['mark'],
										$record['grade'], 'null', 'Fits no requirement. Does not count'));
				}
			} else {
				array_push($ret, array($record['code'], $record['term'], $record['name'], $record['mark'],
										$record['grade'], 'null', 'Failed. Does not count'));
			}
		} else {
			array_push($ret, array($record['code'], $record['term'], $record['name'], $record['mark'],
										$record['grade'], 'null', 'Incomplete. Does not yet count'));
		}
	}
	array_push($ret, array("OVERALL WAM", $wamElem['mark'], $wamElem['uoc']));
	foreach($rules as &$req) {
		if ($req['min'] - $req['complete'] == 0) {
			continue;
		}
		$q = "select name from rules where id = %d";
		$name = dbOneTuple($db, mkSQL($q, $req['rule']))[0];
		array_push($ret, array(($req['complete'])." UOC so far; need ".($req['min'] - $req['complete'])." UOC more",
							$name));
	}

	return $ret; // stub
}


// Task E:

// E.g. $advice = advice($db, 3012345, 162, 164)
// Inputs:
//  $db = open database handle
//  $studentID = People.unswid value (i.e. unsw student id)
//  $currTermID = code for current semester (e.g. "09s2")
//  $nextTermID = code for next semester (e.g. "10s1")
// Outputs:
//  Advice array (see spec for details)

function advice($db, $studentID, $currTermID, $nextTermID)
{
	return array(); // stub
}
?>
