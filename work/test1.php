<?php

function aFunc($arr) {
    $arr['hi'] = 'ok';
}

function bFunc() {
    $arr = array();
    aFunc($arr);
}


class Ob {
	public function oooo() {
		if (true) {
			
?>
<div> hello </div>
<?php

		}
	}
}

$ob = new Ob();
$ob->oooo();

bFunc();
?>
