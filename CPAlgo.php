<?php
include_once "Algo.php";

class CPAlgo implements Algo {
	
	private bool $debug = false;
	private int $visitedNodes = 0;
	public int $visitedNodesAlgo = 0;
	public int $visitedNodesDecomposition = 0;
	public int $visitedNodesHasPath = 0;
	public int $visitedNodesDetection = 0;

	/**
	 * New algorithm: Core: The computation of the relations.
	 */
	public function compute(Net $net) : array {
		$vis = 0;
		$P = [];
		$this->determineHasPath($net);
		foreach ($net->transitions as $t) {
			$vis++;
			$tpost = array_values($t->postset);
			for ($i = 0; $i < count($tpost); $i++) {
				$vis++;
				$x = $tpost[$i]; $P[$x->id] = $x; 
				for ($j = 0; $j < count($tpost); $j++) {
					if ($i === $j) continue;
					$vis += 2;
					$y = $tpost[$j]; $P[$y->id] = $y;
					$hasPathsX = array_diff_key($x->reaches, $y->reaches + $this->virtualNodes);
					
					foreach ($hasPathsX as $sX) {
						$vis++;
						$P[$sX->id] = $sX;
						$hasPathsXY = array_diff_key($y->reaches, $sX->reaches + $this->virtualNodes);
						$P[$sX->id]->parallel += $hasPathsXY;						
					}
				}
			}
		}
		$R = [];
		foreach ($P as $sX) {
			$vis += 1;
			foreach ($sX->parallel as $sY) {
				$vis += 1;
				if ($sX->isLoopNode) {
					$sX->remarkable[$sX->id . '-' . $sY->id] = [$sX, $sY];
					$R[$sX->id . '-' . $sY->id] = [$sX, $sY];
				} else if ($sY->isLoopNode) {
					$sY->remarkable[$sX->id . '-' . $sY->id] = [$sX, $sY];
					$R[$sX->id . '-' . $sY->id] = [$sX, $sY];
				} else {
					$R[$sX->id . '-' . $sY->id] = [$sX, $sY];
					$R[$sY->id . '-' . $sX->id] = [$sY, $sX];
					unset($sY->parallel[$sX->id]);
				}
			}
		}
		$this->visitedNodes += $vis;
		$this->visitedNodesAlgo += $vis;
		return $R;
	}

	/**
	 * New algorithm: Determine all nodes a node has a path to.
	 */
	private function determineHasPath(Net $net) : void {
		$vis = 0;
		$visited = [];
		$current = $net->ends + [];
		$all = $net->transitions + $net->places;
		do {
			$cur = array_shift($current);
			$vis++;
			
			$postset = $cur->postset;
			$vis += count($postset);
			$process = count(array_diff_key($postset, $visited)) === 0;
			$vis += count($postset);
			
			if ($process) {
				$visited[$cur->id] = $cur->id;
				foreach ($cur->postset as $p) $cur->reaches += $p->reaches;
				$vis += count($cur->postset);
				if ($cur instanceof Place) {
					$cur->reaches[$cur->id] = $cur;
				}
				$current = $current + $cur->preset;
			}
		} while (count($current) > 0);
		$this->visitedNodes += $vis;
		$this->visitedNodesHasPath += $vis;
	}
	
	
	/*
	 * Cyclic handling.
	 */
	
	private array $loopNets = [];
	private array $loopNodes = [];
	private array $virtualNodes = [];
	private int $netId = 1;
	private int $newIds = 1;
	private array $uniqueLoops = [];
	private int $numberNets = 0;
	
	public bool $dot = false;
	public string $file = '';
	
	/**
	 * Compute for cyclic.
	 */
	public function computeCyclic(Net $net) : array {
		$vis = 0;
		$net->id = 1;
		// Decompose the net
		$nets = $this->decomposeNet($net);
		$this->numberNets = count($nets);
		// Compute the relations for each net.
		$conc = [];
		foreach ($nets as $k => $net) $conc += $this->compute($net);
		$vis += count($nets);
		// Combine the information between the different nets.
		if (count($this->loopNodes) >= 1) {
			$remarkable = [];
			foreach ($this->loopNodes as $loopNode) $remarkable += $loopNode->remarkable;
			$vis += count($this->loopNodes);
			foreach ($remarkable as $pairId => $pair) {
				$vis++;
				unset($conc[$pairId]);
				$orgX = ($pair[0]->isLoopNode ? $this->loopNets[$pair[0]->id]->places : [ $pair[0] ]);
				$orgY = ($pair[1]->isLoopNode ? $this->loopNets[$pair[1]->id]->places : [ $pair[1] ]);
				foreach ($orgX as $x) {
					$vis++;
					if ($x->isVirtual) continue;
					foreach ($orgY as $y) {
						$vis++;
						if ($y->isVirtual) continue;
						$conc[$x->id . '-' . $y->id] = [$x,$y];
						$conc[$y->id . '-' . $x->id] = [$y,$x];
					}
				}
			}
		}		
		$this->visitedNodes += $vis;
		$this->visitedNodesAlgo += $vis;
		return $conc;
	}

	/**
	 * Recursively decompose the given net.
	 */
	private function decomposeNet(Net $net) : array {
		if ($this->debug) echo "Decompose net " . $net->id . PHP_EOL;
		$vis = 0;
		$loops = $this->detectLoops($net);
		if ($this->dot) file_put_contents($this->file . '_' . $net->id . '-' . $this->visitedNodes . '.dot', Util::toDot($net, $loops));
		if ($this->debug) echo "\tNet " . $net->id . " contains " . count($loops) . " loops" . PHP_EOL;
		if (count($loops) === 0) return [ $net ];
		
		$nets = $this->decomposeLoops($net, $loops);
		$subNets = [];
		foreach ($nets as $net) {
			$vis++;
			$subNets = array_merge($subNets, $this->decomposeNet($net));
		}
		$this->visitedNodes += $vis;
		$this->visitedNodesDecomposition += $vis;
		return $subNets;
	}

	/*
	 * Loop decomposition
	 */
	private function decomposeLoops(Net $net, array $loops) : array {
		$vis = 0;
		// Replace the loops with a place
		$nets = [$net->id => $net];
		
		foreach ($loops as $k => $loop) {
			$uniqueExit = $loop->exits[array_key_first($loop->exits)];
			if ($this->debug) echo "Loop " . $k . " of " . $net->id . PHP_EOL;
			$vis++;
			// Create a new net for the loop;
			if (!array_key_exists($uniqueExit->id, $this->uniqueLoops)) {
				$loopNet = new Net();
				$loopNet->id = $net->id . '_' . $uniqueExit->id;
				$nets[$loopNet->id] = $loopNet;
				$this->uniqueLoops[$uniqueExit->id] = $loopNet;
				
				// Copy the loop nodes
				$org = $loop->nodes + [];
				foreach ($loop->nodes as $node) {
					if ($node instanceof Place) {
						$copy = new Place();
					} else $copy = new Transition();
					$loop->nodes[$node->id] = $copy;
					if (array_key_exists($node->id, $loop->exits)) $loop->exits[$node->id] = $copy;
					if (array_key_exists($node->id, $loop->entries)) $loop->entries[$node->id] = $copy;
					$copy->id = $node->id;
					$copy->name = $node->name;
				}
			
				// Copy post- and presets
				foreach ($org as $n => $node) {
					$inLoop = array_intersect_key($node->preset, $loop->nodes);
					foreach ($inLoop as $k => $pre) $loop->nodes[$n]->preset[$k] = $loop->nodes[$k];
					$inLoop = array_intersect_key($node->postset, $loop->nodes);
					foreach ($inLoop as $k => $post) $loop->nodes[$n]->postset[$k] = $loop->nodes[$k];
				}
				
				// Determine transitions and places
				$loopNet->transitions = array_diff_key($loop->nodes, $net->places);
				$loopNet->places = array_diff_key($loop->nodes, $net->transitions);
			} else $loopNet = null;
			
			// Determine entries and exits in the net.
			if (count($loop->doBody) >= 1) {
				$realEntries = array_intersect_key($loop->exits, $loop->doBody);
			} else {
				$realEntries = $loop->entries;
			}
			$netRealEntries = array_intersect_key($net->places, $realEntries);
			$netExits = array_intersect_key($net->places, $loop->exits);
			
			// Remove all nodes not in the do-body (inclusive the "real" entries).
			$nonDoBody = array_diff_key($loop->nodes, $loop->doBody);
			if ($this->debug) { echo "Net-trans before: "; Util::printSet($net->transitions); }
			$net->transitions = array_diff_key($net->transitions, $nonDoBody);
			if ($this->debug) { echo "Net-trans after:  "; Util::printSet($net->transitions); }
			if ($this->debug) { echo "Net-places before: "; Util::printSet($net->places); }
			$net->places = array_diff_key($net->places, $nonDoBody + $realEntries);
			if ($this->debug) { echo "Net-places after:  "; Util::printSet($net->places); }
		
			// Insert a new loop node for the loop
			$loopNode = new Place();
			$loopNode->isLoopNode = true;
			$loopNode->id = "l_" . $net->id . '_' . $uniqueExit->id;
			$net->places[$loopNode->id] = $loopNode;
			$this->loopNets[$loopNode->id] = $this->uniqueLoops[$uniqueExit->id];
			$this->loopNodes[$loopNode->id] = $loopNode;
			$vis++;
			
			// We are not interested in the entries since they remain.
			// We are interested in exits of the do-body.
			if ($this->debug) { echo "Net real entries: "; Util::printSet($netRealEntries); }
			if ($this->debug) { echo "Net real exits: "; Util::printSet($netExits); }
			foreach ($netRealEntries as $entry) $loopNode->preset += array_diff_key($entry->preset, $nonDoBody);
			foreach ($netExits as $exit) $loopNode->postset += array_diff_key($exit->postset, $loop->nodes);
			$vis += count($realEntries) + count($loop->exits);
						
			// We have to update the predecessors and successors, respectively, of the loop entries and exits.
			foreach ($loopNode->preset as $pred) {
				$pred->postset = array_diff_key($pred->postset, $realEntries) + [$loopNode->id => $loopNode];
				$fId = "lf_" . $this->newIds++;
				$net->flows[$fId] = new Flow($fId, $pred, $loopNode);
			}
			foreach ($loopNode->postset as $succ) {
				$succ->preset = array_diff_key($succ->preset, $loop->exits) + [$loopNode->id => $loopNode];
				$fId = "lf_" . $this->newIds++;
				$net->flows[$fId] = new Flow($fId, $loopNode, $succ);
			}
			$vis += count($loopNode->preset) + count($loopNode->postset);
					
			// Now, update the flows
			foreach ($net->flows as $fId => $flow) {
				$vis++;
				$source = $flow->source; $target = $flow->target;
				
				// There are variants:
				$deleted = false;
				// 1. Parts of the flow are not in the net anymore: Remove from net.
				$srcExists = array_key_exists($source->id, $net->transitions + $net->places);
				$tgtExists = array_key_exists($target->id, $net->transitions + $net->places);
				if (!$srcExists || !$tgtExists) {
					if ($srcExists || $tgtExists) {
						if ($tgtExists) unset($target->preset[$source->id]);
						else unset($source->postset[$target->id]);
					}
					if ($this->debug) echo "Delete " . $flow->source->id . " -> " . $flow->target->id . PHP_EOL;
					unset($net->flows[$fId]);
					$deleted = true;
				}
				// 2. The flow is connected to the loop
				if ($loopNet && (array_key_exists($source->id, $loop->nodes) || array_key_exists($target->id, $loop->nodes))) {
					// a. The flow has a loop exit as target
					if (array_key_exists($target->id, $loop->exits)) {
						// Insert a new start place
						$lTarget = $loop->nodes[$target->id];
						$this->addStart($loopNet, $lTarget, $fId, $this->newIds);
						
						// Insert a new end place
						$lSource = $loop->nodes[$source->id];
						$this->addEnd($loopNet, $lSource, $fId, $this->newIds);						
						
						unset($lTarget->preset[$source->id]);
						unset($lSource->postset[$target->id]);
						
						if (array_key_exists($target->id, $realEntries) && (count($realEntries) === 1 || array_key_exists($source->id, $loop->doBody))) {
							$this->addStart($loopNet, $lTarget, $fId, $this->newIds);							
						}
						$vis += 2;
						
						// We do not need the flow in the loop net, so we do not add it.
					} else {
						// b. The flow is an inner flow, add it.
						if (array_key_exists($source->id, $loop->nodes) && array_key_exists($target->id, $loop->nodes)) {
							$loopNet->flows[$fId] = new Flow($fId, $loop->nodes[$source->id], $loop->nodes[$target->id]);
						}
					}					
				}
			}
			
			if ($this->debug && $loopNet) {
				echo "Check " . $loopNet->id . ": " . PHP_EOL;
				var_dump(Util::checkNet($loopNet));
			}
		}	 
		$this->visitedNodes += $vis;
		$this->visitedNodesDecomposition += $vis;
		/*if ($this->dot) {
			foreach ($nets as $n) file_put_contents($this->file . '_' . $n->id . '.dot', Util::toDot($n));
		}*/
		if ($this->debug) {
			echo "Check " . $net->id . ": " . PHP_EOL;
			var_dump(Util::checkNet($net));
		}
		/*var_dump(array_map(function (Flow $flow) {
			return $flow->source->id . " -> " . $flow->target->id;
		}, $net->flows));*/
		return $nets;
	}
	
	/**
	 * Add a start place with a transition to the given target.
	 */
	private function addStart(Net $net, Node $target, string $fId, int &$i) : void {
		$startTrans = new Transition();
		$startTrans->id = $fId . "_" . $i++;
		$startTrans->postset[$target->id] = $target;
		$target->preset[$startTrans->id] = $startTrans;
		$startTrans->isVirtual = true;
		$this->virtualNodes[$startTrans->id] = $startTrans;
		
		$startPlace = new Place();
		$startPlace->id = $fId . "_" . $i++;
		$startPlace->postset[$startTrans->id] = $startTrans;
		$startTrans->preset[$startPlace->id] = $startPlace;
		$startPlace->isVirtual = true;
		$this->virtualNodes[$startPlace->id] = $startPlace;
		
		$net->flows['se' . $i] = new Flow('se' . $i++, $startTrans, $target);
		$net->flows['se' . $i] = new Flow('se' . $i++, $startPlace, $startTrans);
		$net->places[$startPlace->id] = $startPlace;
		$net->transitions[$startTrans->id] = $startTrans;
		$net->starts[$startPlace->id] = $startPlace;
		$this->visitedNodes += 3;
		$this->visitedNodesDecomposition += 3;
	}
	
	/**
	 * Add an end place with a transition from the given source.
	 */
	private function addEnd(Net $net, Node $source, string $fId, int &$i) : void {
		$endTrans = new Transition();
		$endTrans->id = $fId . "_" . $i++;
		$endTrans->preset[$source->id] = $source;
		$source->postset[$endTrans->id] = $endTrans;
		$endTrans->isVirtual = true;
		$this->virtualNodes[$endTrans->id] = $endTrans;
		
		$endPlace = new Place();
		$endPlace->id = $fId . "_" . $i++;
		$endPlace->preset[$endTrans->id] = $endTrans;
		$endTrans->postset[$endPlace->id] = $endPlace;
		$endPlace->isVirtual = true;
		$this->virtualNodes[$endPlace->id] = $endPlace;
		
		$net->flows['ee' . $i] = new Flow('ee' . $i++, $source, $endTrans);
		$net->flows['ee' . $i] = new Flow('ee' . $i++, $endTrans, $endPlace);
		$net->places[$endPlace->id] = $endPlace;
		$net->transitions[$endTrans->id] = $endTrans;
		$net->ends[$endPlace->id] = $endPlace;
		
		$this->visitedNodes += 3;
		$this->visitedNodesDecomposition += 3;
	}



	/*
	 * Loop detection
	 */

	private array $components = [];		
	private int $index = 0;	
	private array $unindexed = [];	
	private array $stack = [];	
	private array $tmpNodes = [];

	/**
	 * Detect loops in a given net by Tarjan's algorithm.
	 */
	public function detectLoops(Net $net) : array {
		$vis = 1;
		$this->components = [];
		$this->stack = [];
		
		$this->unindexed = $net->places + $net->transitions;
		$this->tmpNodes = $net->places + $net->transitions;
		while (count($this->unindexed) > 0) {
			$vis++;
			$this->strongConnect(array_shift($this->unindexed));
		}
		$this->determineLoopBodies($net, $this->components);
		$this->visitedNodes += $vis;
		$this->visitedNodesDetection += $vis;
		return array_merge([], $this->components);
	}

	/**
	 * Part of detectLoops.
	 */
	private function strongConnect(Node $node) : void {
		$vis = 1;
		$node->index = $this->index;
		unset($this->unindexed[$node->id]);
		$node->lowlink = $this->index++;

		$this->stack[] = $node->id;

		foreach ($node->postset as $s) {
			if (array_key_exists($s->id, $this->unindexed)) {
				$this->strongConnect($s);
				$node->lowlink = min($node->lowlink, $s->lowlink);
			} else if (in_array($s->id, $this->stack, true)) {
				$node->lowlink = min($node->lowlink, $s->index);
			}
		}
		$vis += count($node->postset);
		
		if ($node->lowlink === $node->index) {
			$comp = new Loop();
			$preset = [];
			$postset = [];
			do {
				$vis++;
				$current = array_pop($this->stack);
				$currentNode = $this->tmpNodes[$current];
				$comp->nodes[$current] = $currentNode;
				$preset += $currentNode->preset;
				$postset += $currentNode->postset;
			} while ($current !== $node->id);
			
			if (count($comp->nodes) > 1) {
				// Determine entries and exits
				$preset = array_diff_key($preset, $comp->nodes);
				$postset = array_diff_key($postset, $comp->nodes);
				foreach ($preset as $in) $comp->entries += array_intersect_key($in->postset, $comp->nodes);
				foreach ($postset as $out) $comp->exits += array_intersect_key($out->preset, $comp->nodes);
				$vis += count($preset) + count($postset);
				
				ksort($comp->exits);
				
				$this->components[] = $comp;
			}
			
		}
		$this->visitedNodes += $vis;
		$this->visitedNodesDetection += $vis;
	}
	
	/**
	 * Determines the do-bodies of the loops.
	 */
	private function determineLoopBodies(Net $net, array $loops) : void {
		$vis = 1;
		foreach ($loops as $loop) {
			$vis++;
			if (count($loop->entries) === 1) {
				// We do not have to determine the do-body.
				$loop->doBody = [];
				continue;
			}
			// Determine do-body
			$workingList = $loop->entries + [];
            $doBody = $workingList + [];
			$cut = [];
			foreach ($loop->exits as $exit) $cut += $exit->postset;
			$vis += count($loop->exits);
            while (count($workingList) > 0) {
				$vis++;
                $cur = array_shift($workingList);
				$next = array_intersect_key(array_diff_key($cur->postset, $cut + $doBody), $loop->nodes);
					$doBody += $next;
				$workingList += $next;
            }
			$loop->doBody = $doBody;
		}
		$this->visitedNodes += $vis;
		$this->visitedNodesDecomposition += $vis;
	}
	
	public function generateDotOut(bool $dot) : void {
		$this->dot = $dot;
	}
	
	public function getVisitedNodes() : int {
		return $this->visitedNodes;
	}
	
	public function getNumberLoops() : int {
		return count($this->uniqueLoops);
	}
	
	public function getNumberInvestigatedNets() : int {
		return $this->numberNets;
	}
}
?>