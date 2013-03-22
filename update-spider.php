<?php
include("parser.php");
$params = '{"region" : "1347", "field" : "1"}';
$parser = new HHParser(json_decode($params));
$parser->parse();
?>