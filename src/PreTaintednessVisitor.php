<?php

use Phan\Language\Context;
use ast\Node;
use ast\Node\Decl;
use Phan\Debug;

/**
 * Class for visiting any nodes we want to handle in pre-order.
 *
 * Unlike TaintednessVisitor, this is solely used to set taint
 * on variable objects, and not to determine the taint of the
 * current node, so this class does not return anything.
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
class PreTaintednessVisitor extends TaintednessBaseVisitor {

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
	 * Visit a foreach loop
	 *
	 * This is done in pre-order so that we can handle
	 * the loop condition prior to determine the taint
	 * of the loop variable, prior to evaluating the
	 * loop body.
	 *
	 * @param Node $node
	 */
	public function visitForeach( Node $node ) {
		// TODO: Could we do something better here detecting the array
		// type
		$lhsTaintedness = $this->getTaintedness( $node->children['expr'] );

		$value = $node->children['value'];
		if ( $value->kind === \ast\AST_REF ) {
			// FIXME, this doesn't fully handle the ref case.
			// taint probably won't be propagated to outer scope.
			$value = $value->children['var'];
		}

		if ( $value->kind !== \ast\AST_VAR ) {
			$this->debug( __METHOD__, "FIXME foreach complex case not handled" );
			// Debug::printNode( $node );
			return;
		}

		try {
			$variableObj = $this->getCtxN( $value )->getVariable();
			$this->setTaintedness( $variableObj, $lhsTaintedness );

			if ( isset( $node->children['key'] ) ) {
				// This will probably have a lot of false positives with
				// arrays containing only numeric keys.
				assert( $node->children['key']->kind === \ast\AST_VAR );
				$variableObj = $this->getCtxN( $node->children['key'] )->getVariable();
				$this->setTaintedness( $variableObj, $lhsTaintedness );
			}
		} catch ( Exception $e ) {
			// getVariable can throw an IssueException if var doesn't exist.
			$this->debug( __METHOD__, "Exception " . get_class( $e ) . $e->getMessage() . "" );
		}
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
	 * @see visitMethod
	 * @param Decl $node
	 * @return void Just has a return statement in case visitMethod changes
	 */
	public function visitClosure( Decl $node ) {
		return $this->visitMethod( $node );
	}

	/**
	 * Set the taintedness of parameters to method/function.
	 *
	 * Parameters that are ints (etc) are clearly safe so
	 * this marks them as such. For other parameters, it
	 * creates a map between the function object and the
	 * parameter object so if anyone later calls the method
	 * with a dangerous argument we can determine if we need
	 * to output a warning.
	 *
	 * Also handles FuncDecl and Closure
	 * @param Decl $node
	 */
	public function visitMethod( Decl $node ) {
		// var_dump( __METHOD__ ); Debug::printNode( $node );
		$method = $this->context->getFunctionLikeInScope( $this->code_base );

		$params = $node->children['params']->children;
		$varObjs = [];
		foreach ( $params as $i => $param ) {
			$scope = $this->context->getScope();
			if ( !$scope->hasVariableWithName( $param->children['name'] ) ) {
				// Well uh-oh.
				$this->debug( __METHOD__, "Missing variable for param \$" . $param->children['name'] );
				continue;
			}
			$varObj = $scope->getVariableByName( $param->children['name'] );
			$paramTypeTaint = $this->getTaintByReturnType( $varObj->getUnionType() );
			if ( $paramTypeTaint === SecurityCheckPlugin::NO_TAINT ) {
				// The param is an integer or something, so skip.
				$this->setTaintedness( $varObj, $paramTypeTaint );
				continue;
			}

			// Its going to depend on whether anyone calls the method
			// with something dangerous.
			$this->setTaintedness( $varObj, SecurityCheckPlugin::PRESERVE_TAINT );
			$this->linkParamAndFunc( $varObj, $method, $i );
		}
	}
}
