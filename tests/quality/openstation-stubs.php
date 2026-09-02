<?php
/**
 * Static-analysis declarations for OpenStation's experimental App Framework.
 *
 * This file is loaded only by PHPStan. It is never shipped with the plugin.
 *
 * @package FleetForOpenStation
 */

namespace OpenStation;

\defined( 'OPENSTATION_FLEET_DIR' ) || \define( 'OPENSTATION_FLEET_DIR', '' );
\defined( 'OPENSTATION_FLEET_URL' ) || \define( 'OPENSTATION_FLEET_URL', '' );
\defined( 'WPINC' ) || \define( 'WPINC', 'wp-includes' );

/** Fluent app definition exposed by the experimental runtime. */
final class App {
	public static function define( string $app_id ): self {}
	public function title( string $title ): self {}
	public function icon( string $icon ): self {}
	public function size( int $width, int $height ): self {}
	public function min_size( int $width, int $height ): self {}
	public function placement( string $placement ): self {}
	public function placeable( bool $placeable ): self {}
	public function capabilities( string ...$capabilities ): self {}
	public function style( string $path ): self {}
	public function state( array $state ): self {}
	public function mount( callable $callback ): self {}
	public function title_bar_button( string $id, array $config ): self {}
	public function window_action( string $id, array $config ): self {}
	public function view( callable $view ): self {}
	public function tab( string $id, array $config ): self {}
	public function action( string $id, callable $callback ): self {}
	public function dock_order( int $order ): self {}
}

namespace OpenStation\App;

/** App state made available by the OpenStation runtime. */
final class State {
	/** @return mixed */
	public function get( string $key ) {}

	/** @param mixed $value */
	public function set( string $key, $value ): void {}
}

/** OpenStation host bridge made available to native apps. */
final class Os {
	/** @var Effects */
	public $effects;

	/** @return mixed */
	public function param( string $key, $default = null ) {}

	public function title( string $title ): void {}
	public function badge( int $count ): void {}
	public function toast( string $message ): void {}
	public function open( string $app_id, array $args = array() ): void {}
	public function open_url( string $url, string $title = '' ): void {}
	public function close(): void {}
}

/** Effect queue exposed by the OpenStation host bridge. */
final class Effects {
	/** @param mixed $payload */
	public function add( string $effect, $payload = null ): void {}
}

namespace OpenStation\App\Html;

function esc( string $value ): string {}

/** @param mixed $value */
function json( $value ): string {}
