<?php
/**
 * Plugin Name: RideShare Importer
 * Plugin URI: https://wordpress.org/plugins/rideshare-importer/
 * Description: Import your history of trips from ride sharing applications.
 * Version: 1.0.0
 * Author: Marek Hrabe
 * Author URI: https://github.com/marekhrabe
 * Text Domain: rideshare-importer
 * Domain Path: /languages
 *
 * @package RideShareImporter
 */

namespace RideShareImporter;

require_once __DIR__ . '/class-rideshare-import.php';

$rideshare_importer = new RideShare_Import();
register_importer( 'rideshare', 'RideShare', __( 'Import your <strong>ride history</strong> from ride sharing services.', 'rideshare-importer' ), array( $rideshare_importer, 'dispatch' ) );
