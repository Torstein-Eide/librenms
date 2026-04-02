<?php

$name = 'ups-nut';
$unit_text = 'Volts';
$ds = 'out_voltage';

$ups_name = $vars['nutups'] ?? array_key_first($app->data['UPS'] ?? ['default']);
$rrd_filename = Rrd::name($device['hostname'], ['app', $name, $app->app_id, $ups_name]);

require 'includes/html/graphs/generic_simplex.inc.php';
