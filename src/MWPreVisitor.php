<?php

use Phan\Language\Context;
use ast\Node;
use ast\Node\Decl;
use Phan\Debug;
use Phan\CodeBase;
use Phan\Language\Element\FunctionInterface;

/**
 * Class for visiting any nodes we want to handle in pre-order.
 *
 * Copyright (C) 2017  Brian Wolff <bawolff@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */
class MWPreVisitor extends TaintednessBaseVisitor {

	/**
	 * Ensure type of plugin is instance of MediaWikiSecurityCheckPlugin
	 *
	 * @param CodeBase $code_base
	 * @param Context $context
	 * @param MediaWikiSecurityCheckPlugin $plugin
	 */
	public function __construct(
		CodeBase $code_base,
		Context $context,
		MediaWikiSecurityCheckPlugin $plugin
	) {
		parent::__construct( $code_base, $context, $plugin );
		// Ensure phan knows plugin is right type
		$this->plugin = $plugin;
	}

	/**
	 * Handle any node not otherwise handled.
	 *
	 * Currently a no-op.
	 *
	 * @param Node $node
	 */
	public function visit( Node $node ) {
	}

	/**
	 * @see visitMethod
	 * @param Decl $node
	 * @return void
	 */
	public function visitClosure( Decl $node ) {
		return $this->visitMethod( $node );
	}

	/**
	 * @see visitMethod
	 * @param Decl $node
	 * @return void Just has a return statement in case visitMethod changes
	 */
	public function visitFuncDecl( Decl $node ) {
		return $this->visitMethod( $node );
	}

	/**
	 * Set taint for certain hook types.
	 *
	 * Also handles FuncDecl
	 * @param Decl $node
	 */
	public function visitMethod( Decl $node ) {
		$method = $this->context->getFunctionLikeInScope( $this->code_base );
		$hookType = $this->plugin->isSpecialHookSubscriber( $method->getFQSEN() );
		if ( !$hookType ) {
			return;
		}
		$params = $node->children['params']->children;

		switch ( $hookType ) {
		case '!ParserFunctionHook':
			$this->setFuncHookParamTaint( $params, $method );
			break;
		case '!ParserHook':
			$this->setTagHookParamTaint( $params, $method );
			break;
		}
	}

	/**
	 * Set taint for a tag hook.
	 *
	 * The parameters are:
	 *  string contents (Tainted from wikitext)
	 *  array attribs (Tainted from wikitext)
	 *  Parser object
	 *  PPFrame object
	 *
	 * @param array $params formal parameters of tag hook
	 * @param FunctionInterface $method
	 */
	private function setTagHookParamTaint( array $params, FunctionInterface $method ) {
		// Only care about first 2 parameters.
		for ( $i = 0; $i < 2 && $i < count( $params ); $i++ ) {
			$param = $params[$i];
			$scope = $this->context->getScope();
			if ( !$scope->hasVariableWithName( $param->children['name'] ) ) {
				// Well uh-oh.
				$this->debug( __METHOD__, "Missing variable for param \$" . $param->children['name'] );
				continue;
			}
			$varObj = $scope->getVariableByName( $param->children['name'] );
			$this->setTaintedness( $varObj, SecurityCheckPlugin::YES_TAINT );
			// $this->debug( __METHOD__, "In $method setting param $varObj as tainted" );
		}
	}

	/**
	 * Set the appropriate taint for a parser function hook
	 *
	 * Basically all but the first arg comes from wikitext
	 * and is tainted.
	 *
	 * @todo This is handling SFH_OBJECT type func hooks incorrectly.
	 * @param Node[] $params Children of the AST_PARAM_LIST
	 * @param FunctionInterface $method
	 */
	private function setFuncHookParamTaint( array $params, FunctionInterface $method ) {
		$funcTaint = $this->getTaintOfFunction( $method );
		$varObjs = [];
		foreach ( $params as $i => $param ) {
			if ( $i === 0 ) {
				continue;
			}
			$scope = $this->context->getScope();
			if ( !$scope->hasVariableWithName( $param->children['name'] ) ) {
				// Well uh-oh.
				$this->debug( __METHOD__, "Missing variable for param \$" . $param->children['name'] );
				continue;
			}
			$varObj = $scope->getVariableByName( $param->children['name'] );
			$this->setTaintedness( $varObj, SecurityCheckPlugin::YES_TAINT );
			/*** Is this needed ? Disabling for now.
			if ( isset( $funcTaint[$i] ) ) {
				if ( !$this->isSafeAssignment(
					$funcTaint[$i],
					SecurityCheckPlugin::YES_TAINT
				) ) {
					$funcName = $method->getFQSEN();
					$this->plugin->emitIssue(
						$this->code_base,
						$this->context,
						'SecurityCheckTaintedOutput',
						"Outputting evil HTML from Parser function hook $funcName (case 2)"
							. $this->getOriginalTaintLine( $method )
					);
				}
			}
			*/
		}
	}
}
