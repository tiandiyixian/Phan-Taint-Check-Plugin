<?php

use Wikimedia\Rdbms\MysqlDatabase;

$db = new MysqlDatabase;

$rows = [
	'first' => 1,
	'second' => 2,
	'fifth' => $_GET['fifth']
];

$rows2 = [
	'third' => 'something'
];

$unsafe = [
	"fourth = fourth+" . $_GET['increment']
];

$db->insert(
	'foo',
	$rows + $rows2,
	__METHOD__
);

$db->insert(
	'foo',
	$unsafe,
	__METHOD__
);

$db->insert(
	'foo',
	$rows + $rows2 + $unsafe,
	__METHOD__
);

$db->insert(
	'foo',
	[
		[ 'first' => $_GET['a'] ],
		[ 'first' => $_GET['a'] ],
		[ 'first' => $_GET['a'] ],
	],
	__METHOD__
);

$items = [];
$items[] = [ 'first' => $_GET['a'] ];
$items[] = [ 'first' => $_GET['a'] ];
$items[] = [ 'first' => $_GET['a'] ];

$db->insert( 'foo', $items, __METHOD__ );
