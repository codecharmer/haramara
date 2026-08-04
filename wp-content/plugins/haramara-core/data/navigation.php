<?php
/**
 * Seed navigation menus.
 *
 * Real Haramara launch IA. Consumed by
 * {@see \Haramara\Core\Setup\Installer::install_navigation()}. Items reference
 * page slugs; custom URLs use 'url'.
 *
 * @package Haramara\Core
 * @return array<string,array<int,array<string,string>>>
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'primary' => array(
		array(
			'label' => 'Carta',
			'slug'  => 'carta',
		),
		array(
			'label' => 'Historia',
			'slug'  => 'historia',
		),
		array(
			'label' => 'Eventos',
			'slug'  => 'eventos',
		),
		array(
			'label' => 'Lealtad',
			'slug'  => 'lealtad',
		),
		array(
			'label' => 'Visítanos',
			'slug'  => 'visitanos',
		),
	),
	'footer'  => array(
		array(
			'label' => 'Aviso de privacidad',
			'slug'  => 'aviso-de-privacidad',
		),
		array(
			'label' => 'Términos',
			'slug'  => 'terminos',
		),
	),
);
