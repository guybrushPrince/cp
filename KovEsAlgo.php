<?php
include_once "Algo.php";

class KovEsAlgo implements Algo {	
	
	private int $visitedNodes = 0;

	public function compute(Net $net) : array {
		$vis = 0;
		$R = [];
		foreach ($net->transitions as $t) {
			$vis++;
			$tpost = array_values($t->postset);
			for ($i = 0; $i < count($tpost) - 1; $i++) {
				$vis++;
				for ($j = $i + 1; $j < count($tpost); $j++) {
					$vis += 2;
					$x = $tpost[$i]; $y = $tpost[$j];
					$R[$x->id . '-' . $y->id] = [$x, $y];
					$R[$y->id . '-' . $x->id] = [$y, $x];
				}
			}
		}
		
		$A = [];
		foreach ($net->places as $s) {
			$vis++;
			$A[$s->id] = [];
			foreach ($s->postset as $p) {
				$vis++;
				foreach ($p->postset as $ps) $A[$s->id][$s->id . '-' . $ps->id] = [$s, $ps];
				$vis += count($p->postset);
			}
		}
		
		$E = $R + [];
		
		while (count($E) > 0) {
			$vis++;
			$p = array_shift($E);
			$x = $p[0]; $s = $p[1];
			if (count($s->postset) === 0) continue;
			$t = $s->postset[array_key_first($s->postset)];
			$allIn = true;
			if ($t instanceof Transition) {
				foreach ($t->preset as $pr) {
					$vis++;
					if (!array_key_exists($x->id . '-' . $pr->id, $R)) {
						$allIn = false;
						break;
					}
				}
			}
			if ($allIn) {
				$xA = [];
				foreach ($A[$s->id] as $pa) {
					$vis += 2;
					$xA[$x->id . '-' . $pa[1]->id] = [$x,$pa[1]];
					$xA[$pa[1]->id . '-' . $x->id] = [$pa[1],$x];
				}
				$E = $E + array_diff_key($xA, $R);
				$R = $R + $xA;
			}
		}

		$this->visitedNodes += $vis;
		return $R;
	}
	
	public function getVisitedNodes() : int {
		return $this->visitedNodes;
	}
}
?>