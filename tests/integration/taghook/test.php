<?php

class SomeClass {
	public function register() {
		$parser = new Parser;

		// Register hooks
		$parser->setHook( 'something', [ __CLASS__, 'evil' ] );
		$parser->setHook( 'something', [ 'SecondClass', 'evilAttribs' ] );
		$parser->setHook( 'something', [ 'SecondClass', 'good' ] );
	}

	public static function evil( $content, array $attribs, Parser $parser, PPFrame $frame ) {
		$text = '<div class="toccolours">' . $content . '</div>';
		return $text;
	}

}

class SecondClass {
	public static function evilAttribs( $content, array $attribs, Parser $parser, PPFrame $frame ) {
		$val = '';
		if ( isset( $attribs['value'] ) ) {
			$val = ' title="' . $attribs['value'] . '" ';
		}

		return "<div $val>Some text</div>";
	}

	public static function good( $content, array $attribs, Parser $parser, PPFrame $frame ) {
		return '<div>' . $parser->recursiveTagParse( $content ) . '</div>';
	}
}
