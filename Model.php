<?php
abstract class Node {
	public string $id;
	public string $name;
	
	public array $postset = [];	
	public array $preset = [];	
	public array $reaches = [];
	public array $parallel = [];
	
	public ?int $index = null;
	public ?int $lowLink = null;
	
	public bool $isLoopNode = false;
	public bool $isVirtual = false;
	public array $remarkable = [];
}

class Place extends Node {	
	public bool $hasMarking = false;
}

class Transition extends Node { }

class Flow {
	public string $id;
	public Node $source;
	public Node $target;
	
	public function __construct(string $id, Node $source, Node $target) {
		$this->id = $id;
		$this->source = $source;
		$this->target = $target;
	}
}

class Net {
	public string $id;
	
	public array $places = [];
	public array $transitions = [];
	public array $flows = [];
	public array $starts = [];
	public array $ends = [];
}

class Loop {
	public array $nodes = [];
	public array $entries = [];
	public array $exits = [];
	public array $doBody = [];
}
?>