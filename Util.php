<?php
class Util {
	public static function printSet(array $concurrency) {
		$keys = array_keys($concurrency);
		sort($keys);
		echo json_encode($keys) . PHP_EOL;
		return $concurrency;
	}

	public static function toDot(Net $net, $loops = null) : string {
		if ($loops instanceof Loop) $loops = [ $loops ];
		return 'digraph PNML {' . PHP_EOL .
		implode(PHP_EOL, array_map(function(Node $node) use ($net, $loops) : string {
			return $node->id . '[' . ($node instanceof Transition ? 'shape="box" ' : 'shape="circle" ') . 'label="' . $node->id . '"' . (array_key_exists($node->id, $net->starts) ? ' color = gold' : '') . 
			    (array_key_exists($node->id, $net->ends) ? ' color = aquamarine' : (
				($loops && array_reduce($loops, function (bool $e, Loop $loop) use ($node) { return $e || array_key_exists($node->id, $loop->doBody); }, false) ? 'color = blue' : 
				($loops && array_reduce($loops, function (bool $e, Loop $loop) use ($node) { return $e || array_key_exists($node->id, $loop->nodes); }, false) ? 'color = red' : '')))) . ']';
		}, $net->places + $net->transitions)) . PHP_EOL .
		implode(PHP_EOL, array_map(function(Flow $flow) : string {
			return $flow->source->id . '->' . $flow->target->id;
		}, $net->flows)) . PHP_EOL .
		'}';
	}

	public static function filterMixedForCompare(array $R) : array {
		return array_filter($R, function($k) { return !strpos(' ' . $k, 't'); }, ARRAY_FILTER_USE_KEY);
	}
	
	/**
	 * Determine all files starting at a folder.
	 * @param string $folder The folder.
	 * @param array $files The files.
	 * @param string $fileType The ending of the file.
	 */
	public static function determineFiles(string $folder, array &$files, string $fileType) {
		$folder = dirname(__FILE__) . "/" . $folder;
		$folder = realpath($folder);

		if ($folder) {
			$dir = new RecursiveDirectoryIterator($folder);
			$iterator = new RecursiveIteratorIterator($dir);
			$regex = new RegexIterator($iterator, "/^.+" . $fileType . "$/i", RecursiveRegexIterator::GET_MATCH);
			$regex->next();
			while ($regex->valid()) {
				$file = $regex->current();
				$files[basename($file[0])] = $file[0];
				$regex->next();
			}
		}
	}
	
	/**
	 * Checks if a net is correctly built.
	 * @param Net $net The net.
	 */
	public static function checkNet(Net $net) : array {
		$nodes = $net->transitions + $net->places;
		// Check incorrect flows
		$errorFlows = array_filter($net->flows, function (Flow $flow) use (&$nodes) {
			return (!array_key_exists($flow->source->id, $nodes)) || 
				(!array_key_exists($flow->target->id, $nodes));
		});
		$messages = array_merge(array_map(function (Flow $flow) use (&$nodes) {
			$m = [];
			if (!array_key_exists($flow->source->id, $nodes)) {
				$m[] = $flow->id . ' misses source ' . $flow->source->id . ' in nodes';
			}
			if (!array_key_exists($flow->target->id, $nodes)) {
				$m[] = $flow->id . ' misses target ' . $flow->target->id . ' in nodes';
			}
			return $m;
		}, $errorFlows));
		// Check unmatching pre- and postsets
		$correct = array_filter($net->flows, function (Flow $flow) use (&$errorFlows) {
			return !array_key_exists($flow->id, $errorFlows);
		});
		$incorrect = array_filter($correct, function (Flow $flow) {
			return (!array_key_exists($flow->source->id, $flow->target->preset)) || 
				(!array_key_exists($flow->target->id, $flow->source->postset));
		});
		$messages = array_merge($messages, array_map(function (Flow $flow) {
			$m = [];
			if (!array_key_exists($flow->source->id, $flow->target->preset)) {
				$m[] = $flow->target->id . ' misses source ' . $flow->source->id . ' in preset';
			}
			if (!array_key_exists($flow->target->id, $flow->source->postset)) {
				$m[] = $flow->source->id . ' misses target ' . $flow->target->id . ' in postset';
			}
			return $m;
		}, $incorrect));
		// Check missing flows (or too much nodes in preset and postset)
		$flows = array_reduce($correct, function (array $f, Flow $fl) {
			$f[$fl->source->id . '<->' . $fl->target->id] = $fl;
			return $f;
		}, []);
		foreach ($nodes as $node) {
			$tooMuchPre = array_filter($node->preset, function (Node $con) use ($node, &$flows) {
				$id = $con->id . '<->' . $node->id;
				return !array_key_exists($id, $flows);
			});
			$tooMuchPost = array_filter($node->postset, function (Node $con) use ($node, &$flows) {
				$id = $node->id . '<->' . $con->id;
				return !array_key_exists($id, $flows);
			});
			foreach ($tooMuchPre as $pre)   $messages[] = $node->id . " has a wrong " . $pre->id . " in its preset";
			foreach ($tooMuchPost as $post) $messages[] = $node->id . " has a wrong " . $post->id . " in its postset";
				
		}
		return $messages;
	}
}
?>