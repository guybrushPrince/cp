<?php
include_once "Model.php";

class Parser {
	/**
	 *
	 */
	public static function parsePNMLArray(array $xml) : array {
		$nets = [];
		foreach ($xml as $pnml) {
			if (array_key_exists("name", $pnml) && $pnml["name"] === "PNML") {
				foreach ($pnml["children"] as $net) {
					if (array_key_exists("name", $net) && $net["name"] === "NET") {
						$netObj = new Net();
						$nets[] = $netObj;
						foreach ($net["children"] as $node) {
							if (array_key_exists("name", $node)) {
								switch ($node["name"]) {
									case "PLACE": {
										$place = new Place();
										$place->id = $node["attrs"]["ID"];
										$place->name = $node["children"][0]["children"][0]["tagData"];
										if (count($node["children"]) > 1) {
											$place->hasMarking = true;
										}
										$netObj->places[$place->id] = $place;
									} break;
									case "TRANSITION": {
										$transition = new Transition();
										$transition->id = $node["attrs"]["ID"];
										$transition->name = $node["children"][0]["children"][0]["tagData"];
										$netObj->transitions[$transition->id] = $transition;
									} break;
									case "ARC": {
										$id = $node["attrs"]["ID"];
										$source = $node["attrs"]["SOURCE"];
										$target = $node["attrs"]["TARGET"];
										if (substr($source, 0, 1) === 't') {
											$source = $netObj->transitions[$source];
											$target = $netObj->places[$target];
										} else {
											$target = $netObj->transitions[$target];
											$source = $netObj->places[$source];
										}
										$netObj->flows[$id] = new Flow($id, $source, $target);
									} break;
								}
							}
						}
					}
				}
			}
		}
		return $nets;
	}
	
	/**
	 *
	 */
	public static function isCyclic(Net $net) : bool {
		$visited = [];
		$recStack = [];
		
		foreach ($net->starts as $start) {
			if (self::isCyclicUtil($start, $visited, $recStack)) return true;
		}
		return false;
	}
	public static function isCyclicUtil(Node $node, array &$visited, array &$recStack) : bool {
		if (array_key_exists($node->id, $recStack) && $recStack[$node->id]) return true;
		if (array_key_exists($node->id, $visited) && $visited[$node->id]) return false;
		
		$visited[$node->id] = true;
		$recStack[$node->id] = true;
		foreach ($node->postset as $succ) {
			if (self::isCyclicUtil($succ, $visited, $recStack)) return true;
		}
		$recStack[$node->id] = false;
		
		return false;
	}

	/**
	 *
	 */
	public static function determinePrePostSets(array $nets) : void {
		foreach ($nets as $net) {
			foreach ($net->flows as $flow) {
				$flow->source->postset[$flow->target->id] = $flow->target;
				$flow->target->preset[$flow->source->id] = $flow->source;
			}
			$net->starts = array_filter($net->places + $net->transitions, function(Node $node) {
			return count($node->preset) === 0;
			});
			$net->ends = array_filter($net->places + $net->transitions, function(Node $node) {
				return count($node->postset) === 0;
			});
		}
	}
	
	public static function parseJSON(string $json) : array {
		$model = json_decode($json, JSON_OBJECT_AS_ARRAY);
		$net = new Net();
		$maxId = 0;
		foreach ($model["nodes"] as $node) {
			switch ($node["type"]) {
				case "XOR":
				case "TASK":
				case "EVENT": {
					$place = new Place();
					$place->id = 'n' . $node["id"];
					$place->name = $node["name"];
					$net->places[$place->id] = $place;
				} break;
				case "START": {
					$place = new Place();
					$place->id = 'n' . $node["id"];
					$place->name = $node["name"];
					$net->places[$place->id] = $place;
					$net->starts[$place->id] = $place;
				} break;
				case "END": {
					$place = new Place();
					$place->id = 'n' . $node["id"];
					$place->name = $node["name"];
					$net->places[$place->id] = $place;
					$net->ends[$place->id] = $place;
				} break;
				case "OR":
				case "AND": {
					$transition = new Transition();
					$transition->id = 'n' . $node["id"];
					$transition->name = $node["name"];
					$net->transitions[$transition->id] = $transition;
				} break;
			}
			$maxId = max($maxId, $node["id"]);
		}
		$maxId += 1;
		$nodes = $net->places + $net->transitions;
		foreach ($model["edges"] as $edge) {
			$source = $nodes['n' . $edge["from"]];
			$target = $nodes['n' . $edge["to"]];
			if ($source instanceof Place && $target instanceof Place) {
				$transition = new Transition();
				$transition->id = 'n' . ($maxId++);
				$transition->name = 't' . $source->name . '_' . $target->name;
				$net->transitions[$transition->id] = $transition;
				
				$source->postset[$transition->id] = $transition;
				$transition->preset[$source->id] = $source;
				$fId = "f" . $source->id . '_' . $transition->id;
				$net->flows[$fId] = new Flow($fId, $source, $transition);
				$source = $transition;
			} else if ($source instanceof Transition && $target instanceof Transition) {
				$place = new Place();
				$place->id = 'n' . ($maxId++);
				$place->name = 'p' . $source->name . '_' . $target->name;
				$net->places[$place->id] = $place;				
				
				$source->postset[$place->id] = $place;
				$place->preset[$source->id] = $source;
				$fId = "f" . $source->id . '_' . $place->id;
				$net->flows[$fId] = new Flow($fId, $source, $place);
				$source = $place;
			}
			$source->postset[$target->id] = $target;
			$target->preset[$source->id] = $source;
			$fId = "f" . $source->id . '_' . $target->id;
			$net->flows[$fId] = new Flow($fId, $source, $target);
			$source = $target;
		}
		// Check all places if it fulfills the free-choice property
		foreach ($net->places as $pId => $place) {
			if (count($place->postset) >= 2) {
				foreach ($place->postset as $tId => $transition) {
					if (count($transition->preset) > 1) {
						// Error
						// Insert a new transition and place
						$newTrans = new Transition();
						$newTrans->id = 'n' . ($maxId++);
						$newTrans->name = 't' . $place->name . '_' . $transition->name;
						$net->transitions[$newTrans->id] = $newTrans;
						
						unset($place->postset[$tId]);
						unset($transition->preset[$pId]);
						$place->postset[$newTrans->id] = $newTrans;
						$newTrans->preset[$place->id] = $place;
						$fId = "f" . $place->id . '_' . $newTrans->id;
						$net->flows[$fId] = new Flow($fId, $place, $newTrans);
						
						$newPlace = new Place();
						$newPlace->id = 'n' . ($maxId++);
						$newPlace->name = 'p' . $place->name . '_' . $transition->name;
						$net->places[$newPlace->id] = $newPlace;				
						
						$newTrans->postset[$newPlace->id] = $newPlace;
						$newPlace->preset[$newTrans->id] = $newTrans;
						$fId = "f" . $newTrans->id . '_' . $newPlace->id;
						$net->flows[$fId] = new Flow($fId, $newTrans, $newPlace);
						
						$newPlace->postset[$transition->id] = $transition;
						$transition->preset[$newPlace->id] = $newPlace;
						$fId = "f" . $newPlace->id . '_' . $transition->id;
						$net->flows[$fId] = new Flow($fId, $newPlace, $transition);
						
						foreach ($net->flows as $fId => $flow) {
							if ($flow->source->id === $place->id &&
							    $flow->target->id === $transition->id) {
								unset($net->flows[$fId]);
							}
						}
					}
				}
			}
		}
		return [ $net ];
	}
}
?>