<?php

include_once "Xml2Array.php";
include_once "Model.php";
include_once "Util.php";
include_once "Parser.php";
include_once "CPAlgo.php";
include_once "KovEsAlgo.php";

$fileType = ".pnml";
$json = false;
if (in_array("json", $argv)) {
	$fileType = ".json";
	$json = true;
	include_once "soundSAP.php";
} else {
	include_once "sound.php";
}

$dot = in_array("dot", $argv);
$without = in_array("without", $argv);

$arguments = array_diff($argv, ["json", "dot", "without"]);
 
$files = [];
$folder = $arguments[1];
if (strpos($folder, $fileType) > 0) {
	$files = [ $folder ];
	$sound = $files;
} else Util::determineFiles($folder, $files, $fileType);

if (count($arguments) >= 3) {
	$out = $arguments[2];
} else {
	$out = "times.csv";
}

$results = [];
foreach ($files as $file) {
	if (!in_array(basename($file), $sound)) continue;
	$content = file_get_contents($file);
	if (!$json) {
		$xml = (new Xml2Array())->parse($content);
	 
		$nets = Parser::parsePNMLArray($xml);
		Parser::determinePrePostSets($nets);
	} else {
		$nets = Parser::parseJSON($content);
	}
	
	foreach ($nets as $nr => $net) {
		if (!$without) echo $file . PHP_EOL;
		
		$acyclic = !Parser::isCyclic($net);
		
		if ($dot) file_put_contents($file . '.dot', Util::toDot($net));
		
		$kovEs = new KovEsAlgo();
		$ts = microtime(true);
		$kovEsRes = $kovEs->compute($net);
		$tKovEs = microtime(true) - $ts;
			
		$CP = new CPAlgo();
		// DELETE
		$CP->dot = $dot;
		$CP->file = $file;
		$ts = microtime(true);
		$CPRes = $CP->computeCyclic($net);
		$tCP = microtime(true) - $ts;
		
		$diffs = array_diff_key($kovEsRes, $CPRes) + array_diff_key($CPRes, $kovEsRes);
		if (count($diffs) > 0) {
			echo $file . ':' . PHP_EOL;
			Util::printSet($diffs);
			echo "CP vs. KovEs: "; Util::printSet(array_diff_key($CPRes, $kovEsRes));
			echo "KovEs vs. CP: "; Util::printSet(array_diff_key($kovEsRes, $CPRes));
		}
		
		$results[] = [
			"file" => $file,
			"net"  => $nr + 1,
			"places" => count($net->places),
			"transitions" => count($net->transitions),
			"nodes" => count($net->places + $net->transitions),
			"flows" => count($net->flows),
			"cyclic" => !$acyclic ? "TRUE" : "FALSE",
			"relations" => count($CPRes),
			"CP" => sprintf('%f', $tCP),
			"KovEs" => sprintf('%f', $tKovEs),
			"CPNodes" => $CP->getVisitedNodes(),
			"KovEsNodes" => $kovEs->getVisitedNodes(),
			"all.loops" => $CP->getNumberLoops(),
			"investigated.nets" => $CP->getNumberInvestigatedNets(),
			"nodes.has.paths" => $CP->visitedNodesHasPath,
			"nodes.algo" => $CP->visitedNodesAlgo,
			"nodes.decomposition" => $CP->visitedNodesDecomposition,
			"nodes.detection" => $CP->visitedNodesDetection,
			"diffs" => count(array_diff_key($CPRes, $kovEsRes) + array_diff_key($kovEsRes, $CPRes))
		];
	}
	
	unset($nets);
	unset($xml);
	unset($content);
}

if (count($results) >= 1) {
	$first = $results[0];
	$csv = implode(";", array_keys($first)) . PHP_EOL;
	$csv .= implode(PHP_EOL, array_map(function (array $r) {
		return implode(";", $r);
	}, $results));
	file_put_contents($out, $csv);
}
?>