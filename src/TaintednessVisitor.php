<?php declare(strict_types=1);

use Phan\AST\AnalysisVisitor;
use Phan\AST\ContextNode;
use Phan\CodeBase;
use Phan\Language\Context;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Func;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\Method;
use Phan\Language\Element\Variable;
use Phan\Language\UnionType;
use Phan\Language\FQSEN\FullyQualifiedFunctionLikeName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Plugin;
use Phan\Plugin\PluginImplementation;
use ast\Node;
use ast\Node\Decl;
use Phan\Exception\IssueException;
use Phan\Debug;
use Phan\Language\Scope\FunctionLikeScope;
use Phan\Language\Scope\BranchScope;
use Phan\Library\Set;

/**
 * This class visits all the nodes in the ast. It has two jobs:
 *
 * 1) Return the taint value of the current node we are visiting.
 * 2) In the event of an assignment (and similar things) propogate
 *  the taint value from the left hand side to the right hand side.
 *
 * For the moment, the taint values are stored in a "taintedness"
 * property of various phan TypedElement objects. This is probably
 * not the best solution for where to store the data, but its what
 * this does for now.
 *
 * This also maintains some other properties, such as where the error
 * originates, and dependencies in certaint cases.
 */
class TaintednessVisitor extends TaintednessBaseVisitor {

	/**
	 * Generic visitor when we haven't defined a more specific one.
	 *
	 * @param Node $node
	 * @return int The taintedness of the node.
	 */
	public function visit (Node $node) : int
	{
		// This method will be called on all nodes for which
		// there is no implementation of it's kind visitor.
		//
		// To see what kinds of nodes are passing through here,
		// you can run `Debug::printNode($node)`.
	/*	echo __METHOD__ . ' ';
		//var_dump( $this->context );
		echo ' ';
		 Debug::printNode($node); */
		#echo __METHOD__  . $this->dbgInfo() . " setting unknown taint for " . \ast\get_kind_name( $node->kind ) . "\n";
		#Debug::printNode( $node );
		$this->debug( __METHOD__, "unhandled case " . \ast\get_kind_name( $node->kind ) );
		return SecurityCheckPlugin::UNKNOWN_TAINT;
	}


	public function visitFuncDecl( Decl $node ) : int {
		return $this->visitMethod( $node );
	}

	/**
	 * Also handles FuncDecl
	 */
	public function visitMethod( Decl $node ) : int {
		$method = $this->context->getFunctionLikeInScope( $this->code_base );
		if (
			$this->getBuiltinFuncTaint( $method->getFQSEN() ) === null &&
			!$method->getHasYield() &&
			!$method->getHasReturn() &&
			!property_exists( $method, 'funcTaint' )
		) {
			// At this point, if func exec's stuff, funcTaint
			// should already be set.

			// So we have a func with no yield, return and no
			// dangerous side effects. Which seems odd, since
			// what's the point, but mark it as safe.

			// FIXME: In the event that the method stores its arg
			// to a class prop, and that class prop gets output later
			// somewhere else - the exec status of this won't be detected
			// until later, so setting this to NO_TAINT here might miss
			// some issues in the inbetween period.
			$this->setFuncTaint( $method, [ 'overall' => SecurityCheckPlugin::NO_TAINT ] );

			// $this->debug( __METHOD__, $method . " is set to no taint due to lack of return and side effects." );
		}
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	// No-ops we ignore.
	// separate methods so we can use visit to output debugging
	// for anything we miss.

	public function visitStmtList( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitArgList( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitParamList( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	/**
	 * Params should be handled in PreTaintednessVisitor
	 */
	public function visitParam( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitClass( Decl $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitClassConstDecl( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitIf( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitThrow( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	// Actual property decleration is PropElem
	public function visitPropDecl( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitConstElem( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitUse( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitBreak( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitContinue( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitGoto( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitCatch( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitNamespace( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitSwitch( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitSwitchCase( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitWhile( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitUnset( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitTry( Node $node ) : int {
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitAssignOp( Node $node ) : int {
		return $this->visitAssign( $node );
	}

	/**
	 * Also handles visitAssignOp
	 */
	public function visitAssign( Node $node ) : int {
		//echo __METHOD__ . $this->dbgInfo() . ' ';
		// Debug::printNode($node);

		// FIXME This is wrong for non-local vars (including class props)
		// Depending on order of methods in the class file.

		// Make sure $foo[2] = 0; doesn't kill taint of $foo generally.
		$override = $node->children['var']->kind !== \ast\AST_DIM;
		try {
			$variableObjs = $this->getPhanObjsForNode( $node->children['var'] );
		} catch ( Exception $e ) {
			echo __METHOD__ . " FIXME Cannot understand RHS. " . get_class($e) . " - {$e->getMessage()}\n";
			//Debug::printNode( $node );
			return SecurityCheckPlugin::UNKNOWN_TAINT;
		}
		$lhsTaintedness = $this->getTaintedness( $node->children['var'] );
		# $this->debug( __METHOD__, "Getting taint LHS = $lhsTaintedness:" );
		$rhsTaintedness = $this->getTaintedness( $node->children['expr'] );
		# $this->debug( __METHOD__, "Getting taint RHS = $rhsTaintedness:" );

		if ( $node->kind === \ast\AST_ASSIGN_OP ) {
			// TODO, be more specific for different OPs
			// Expand rhs to include implicit lhs ophand.
			$rhsTaintedness = $this->mergeAddTaint( $rhsTaintedness, $lhsTaintedness );
		}

		/* if ( !(SecurityCheckPlugin::PRESERVE_TAINT & $rhsTaintedness) && (SecurityCheckPlugin::PRESERVE_TAINT & $lhsTaintedness) ) {
			$this->debug( __METHOD__, "Preserve on LHS but not RHS in assignment" );
		} */

		// If we're assigning to a variable we know will be output later
		// raise an issue now.
		if ( !$this->isSafeAssignment( $lhsTaintedness, $rhsTaintedness ) ) {
			$this->plugin->emitIssue(
				$this->code_base,
				$this->context,
				'SecurityCheckTaintedOutput',
				"Assigning to a variable that is later output (lhs=$lhsTaintedness; rhs=$rhsTaintedness)"
					. $this->getOriginalTaintLine( $node->children['var'] )
			);
		}
		foreach( $variableObjs as $variableObj ) {
			// echo $this->dbgInfo() . " " . $variableObj . " now merging in taintedness " . $rhsTaintedness . " (previously $lhsTaintedness)\n";
			$this->setTaintedness( $variableObj, $rhsTaintedness, $override );
			try {
				if ( !is_object( $node->children['expr'] ) ) {
					continue;
				}
				$rhsObjs = $this->getPhanObjsForNode( $node->children['expr'] );
			} catch ( Exception $e ) {
				$this->debug( __METHOD__, "Cannot get phan object for RHS of assign " . get_class( $e ) . $e->getMessage() );
				continue;
			}
			foreach ( $rhsObjs as $rhsObj ) {
				$this->mergeTaintDependencies( $variableObj, $rhsObj );
			} 
		}
		return $rhsTaintedness;
	}

	public function visitBinaryOp( Node $node ) : int {

		$safeBinOps =
			// Unsure about BITWISE ops, since
			// "A" | "B" still is a string
			// so skipping.
			// Add is not included due to array addition.
			\ast\flags\BINARY_BOOL_XOR |
			\ast\flags\BINARY_DIV |
			\ast\flags\BINARY_IS_EQUAL |
			\ast\flags\BINARY_IS_IDENTICAL |
			\ast\flags\BINARY_IS_NOT_EQUAL |
			\ast\flags\BINARY_IS_NOT_IDENTICAL |
			\ast\flags\BINARY_IS_SMALLER |
			\ast\flags\BINARY_IS_SMALLER_OR_EQUAL |
			\ast\flags\BINARY_MOD |
			\ast\flags\BINARY_MUL |
			\ast\flags\BINARY_POW |
			\ast\flags\BINARY_SUB |
			\ast\flags\BINARY_BOOL_AND |
			\ast\flags\BINARY_BOOL_OR |
			\ast\flags\BINARY_IS_GREATER |
			\ast\flags\BINARY_IS_GREATER_OR_EQUAL;

		if ( $node->flags & $safeBinOps !== 0 ) {
			return SecurityCheckPlugin::NO_TAINT;
		}

		// Otherwise combine the ophand taint.
		$leftTaint = $this->getTaintedness( $node->children['left'] );
		$rightTaint = $this->getTaintedness( $node->children['right'] );
		$res = $this->mergeAddTaint( $leftTaint, $rightTaint );
		return $res;
	}

	public function visitDim( Node $node ) : int {
		return $this->getTaintednessNode( $node->children['expr'] );
	}

	public function visitPrint( Node $node ) : int {
		return $this->visitEcho( $node );
	}

	public function visitIncludeOrEval( Node $node ) : int {
		// FIXME this should be handled differently,
		// since any taint is bad for this case unlike echo
		return $this->visitEcho( $node );
	}

	/**
	 * Also handles print, eval() and include() (for now).
	 */
	public function visitEcho( Node $node ) : int {
		$taintedness = $this->getTaintedness( $node->children['expr'] );
		# $this->debug( __METHOD__, "Echoing with taint $taintedness" );
		if ( $taintedness & SecurityCheckPlugin::HTML_TAINT ) {
			$this->plugin->emitIssue(
				$this->code_base,
				$this->context,
				$this->isLikelyFalsePositive( $taintedness ) ?
					'SecurityCheckTaintedOutputLikelyFalsePositive' :
					'SecurityCheckTaintedOutput',
				"Echoing tainted expression ($taintedness)"
					. $this->getOriginalTaintLine( $node->children['expr'] )
			);
		} elseif ( is_object( $node->children['expr'] )||$taintedness & SecurityCheckPlugin::PRESERVE_TAINT ) {
			$phanObjs = $this->getPhanObjsForNode( $node->children['expr'] );
			foreach( $phanObjs as $phanObj ) {
				$this->debug( __METHOD__, "Setting $phanObj exec due to echo" );
				// FIXME, maybe not do this for local variables
				// since they don't have other code paths that can set them.
				$this->markAllDependentMethodsExec( $phanObj );
			}
			/*if ( $this->context->isInFunctionLikeScope() ) {
				$func = $this->context->getFunctionLikeInScope( $this->code_base );
				$this->setTaintedness( $func, SecurityCheckPlugin::EXEC_TAINT );
				// A future TODO would be to make this more specific so
				// its per parameter.
			} else {
				echo __METHOD__ . $this->dbgInfo() . " in global context (FIXME)";
			}
			*/
		}
		return SecurityCheckPlugin::NO_TAINT;
	}

	public function visitStaticCall( Node $node ) : int {
		return $this->visitMethodCall( $node );
	}

	public function visitNew( Node $node ) : int {
		if ( $node->children['class']->kind === \ast\AST_NAME ) {
			return $this->visitMethodCall( $node );
		} else {
			return SecurityCheckPlugin::UNKNOWN_TAINT;
			$this->debug( __METHOD__, "cannot understand new" );
		}
	}

	/**
	 * This handles MethodCall and StaticCall
	 */
	public function visitMethodCall( Node $node ) : int {
		$oldMem = memory_get_peak_usage();
		$ctxNode = new ContextNode(
			$this->code_base,
			$this->context,
			$node
		);
		$isStatic = ($node->kind === \ast\AST_STATIC_CALL);
		$isFunc = ($node->kind === \ast\AST_CALL);

		// First we need to get the taintedness of the method
		// in question.
		try {
			if ( $node->kind === \ast\AST_NEW ) {
				$clazzName = $node->children['class']->children['name'];
				$fqsen = FullyQualifiedMethodName::fromStringInContext( $clazzName . '::__construct', $this->context );
				if ( !$this->code_base->hasMethodWithFQSEN( $fqsen ) ) {
					echo __METHOD__ . "FIXME no constructor or parent class";
					throw new exception( "Cannot find __construct" );
				}
				$func = $this->code_base->getMethodByFQSEN( $fqsen );
			} elseif ( $isFunc ) {
				if ( $node->children['expr']->kind !== \ast\AST_NAME ) {
					throw new Exception( "Non-simple func call" );
				}
				$func = $ctxNode->getFunction( $node->children['expr']->children['name'] );
			} else {
				$methodName = $node->children['method'];
				$func = $ctxNode->getMethod( $methodName, $isStatic );
			}
			$funcName = $func->getFQSEN();
			$taint = $this->getTaintOfFunction( $func );
		} catch( IssueException $e ) {
			$this->debug( __METHOD__, "FIXME complicated case not handled. Maybe func not defined." . $e->getIssueInstance() );
			$func = null;
			$funcName = '[UNKNOWN FUNC]';
			$taint = [ 'overall' => SecurityCheckPlugin::UNKNOWN_TAINT ];
		} catch ( Exception $e ) {
			$this->debug( __METHOD__, "FIXME complicated case not handled. Maybe func not defined. " . get_class( $e ) . $e->getMessage() );
			$func = null;
			$funcName = '[UNKNOWN FUNC]';
			$taint = [ 'overall' => SecurityCheckPlugin::UNKNOWN_TAINT ];
		}

		// Now we need to look at the taintedness of the arguments
		// we are passing to the method.
		$overallArgTaint = SecurityCheckPlugin::NO_TAINT;
		$overallTaintHist = '';
		$args = $node->children['args']->children;
		foreach( $args as $i => $argument ) {
			if ( !is_object( $argument ) ) {
				// Literal value
				continue;
			}

			$curArgTaintedness = $this->getTaintednessNode( $argument );
			if ( isset( $taint[$i] ) ) {
				$effectiveArgTaintedness = $curArgTaintedness & 
					( $taint[$i] | $this->execToYesTaint( $taint[$i] ) );
				# $this->debug( __METHOD__, "effective $effectiveArgTaintedness via arg $i $funcName" );
			} elseif ( ( $taint['overall'] &
				( SecurityCheckPlugin::PRESERVE_TAINT | SecurityCheckPlugin::UNKNOWN_TAINT )
			) ) {
				// No info for this specific parameter, but
				// the overall function either preserves taint
				// when unspecified or is unknown. So just
				// pass the taint through.
				// FIXME, could maybe check if type is safe like int.
				$effectiveArgTaintedness = $curArgTaintedness;
				# $this->debug( __METHOD__, "effective $effectiveArgTaintedness via preserve or unkown $funcName" );
			} else {
				// This parameter has no taint info.
				// And overall this function doesn't depend on param
				// for taint and isn't unknown.
				// So we consider this argument untainted.
				$effectiveArgTaintedness = SecurityCheckPlugin::NO_TAINT;
				# $this->debug( __METHOD__, "effective $effectiveArgTaintedness via no taint info $funcName" );
			}

			// -------Start complex reference parameter bit--------/
			// FIXME This is ugly and hacky and needs to be refactored.
			// If this is a call by reference parameter,
			// link the taintedness variables.
			$param = $func ? $func->getParameterForCaller( $i ) : null;
			if ( $param && $param->isPassByReference() ) {
				if ( !$func->getInternalScope()->hasVariableWithName( $param->getName() ) ) {
					$this->debug( __METHOD__, "Missing variable in scope \$" . $param->getName() );
					continue;
				}
				$methodVar = $func->getInternalScope()->getVariableByName( $param->getName() );
				// FIXME: Better to keep a list of dependencies
				// like what we do for methods?
				// Iffy if this will work, because phan replaces
				// the Parameter objects with ParameterPassByReference,
				// and then unreplaces them
				//echo __METHOD__ . $this->dbgInfo() . (string)$param. "\n";

				$pobjs = $this->getPhanObjsForNode( $argument );
				if ( count( $pobjs ) !== 1 ) {
					echo __METHOD__ . $this->dbgInfo() . "Expected only one " . (string)$param . "\n";
				}
				foreach( $pobjs as $pobj ) {
					// FIXME, is unknown right here.
					$combinedTaint = $this->mergeAddTaint( 
						$methodVar->taintedness ?? SecurityCheckPlugin::UNKNOWN_TAINT,
						$pobj->taintedness ?? SecurityCheckPlugin::UNKNOWN_TAINT
					);
					$pobj->taintedness = $combinedTaint;
					$methodVar->taintedness =& $pobj->taintedness;
					$methodLinks = $methodVar->taintedMethodLinks ?? new Set;
					$pobjLinks = $pobj->taintedMethodLinks ?? new Set;
					$pobj->taintedMethodLinks = $methodLinks->union( $pobjLinks );
					$methodVar->taintedMethodLinks =& $pobj->taintedMethodLinks;
					$combinedOrig = ($pobj->taintedOriginalError ?? '' ) . ( $methodVar->taintedOriginalError ?? '' );
					if ( strlen( $combinedOrig ) > 255 ) {
						$combinedOrig = substr( $combinedOrig, 0, 250 ) . '...';
					}
					$pobj->taintedOriginalError = $combinedOrig;
					$methodVar->taintedOriginalError =& $pobj->taintedOriginalError;
				}
			}
			// ------------END complex by reference parameter bit------

			// We are doing something like someFunc( $evilArg );
			// Propogate that any vars set by someFunc should now be
			// marked tainted.
			// FIXME: We also need to handle the case where
			// someFunc( $execArg ) for pass by reference where
			// the parameter is later executed outside the func.
			if ( $func && $this->isYesTaint( $curArgTaintedness ) ) {
				# $this->debug( __METHOD__, "cur arg $i is YES taint ($curArgTaintedness). Marking dependent $funcName" );
				// Mark all dependent vars as tainted.
				$this->markAllDependentVarsYes( $func, $i );
			}

			// We are doing something like evilMethod( $arg );
			// where $arg is a parameter to the current function.
			// So backpropagate that assigning to $arg can cause evilness.
			if ( $this->isExecTaint( $taint[$i] ?? 0 ) ) {
				# $this->debug( __METHOD__, "cur param is EXEC. $funcName" );
				try { 
					$phanObjs = $this->getPhanObjsForNode( $argument );
					foreach ( $phanObjs as $phanObj ) {
						$this->markAllDependentMethodsExec( $phanObj );
					}
				} catch ( Exception $e ) {
					$this->debug( __METHOD__, "FIXME " . get_class( $e ) . " " . $e->getMessage() );
				}
			}
			$taintedArg = $argument->children['name'] ?? '[arg #' . ($i+1) . ']';
			// We use curArgTaintedness here, as we aren't checking what taint
			// gets passed to return value, but which taint is EXECed.
			//$this->debug( __METHOD__, "Checking safe assing $funcName arg=$i paramTaint= " .( $taint[$i] ?? "MISSING" ). " vs argTaint= $curArgTaintedness"  );
			if ( !$this->isSafeAssignment( $taint[$i] ?? 0, $curArgTaintedness ) ) {
				$containingMethod = $this->getCurrentMethod();
				$this->plugin->emitIssue(
					$this->code_base,
					$this->context,
					$this->isLikelyFalsePositive( $curArgTaintedness ) ?
						'SecurityCheckTaintedOutputLikelyFalsePositive' :
						'SecurityCheckTaintedOutput',
					"Calling Method $funcName in $containingMethod" .
					" that outputs using tainted ($curArgTaintedness; " .
					( $taint[$i] ?? 0 ) . ") argument \$$taintedArg." .
					( $func ? $this->getOriginalTaintLine( $func ) : '' ).
					$this->getOriginalTaintLine( $argument ) 
				);
			}

			$overallTaintHist .= $this->getOriginalTaintLine( $argument );
			$overallArgTaint |= $effectiveArgTaintedness;
		}

		if ( $this->isExecTaint( $taint['overall'] ) ) {
			$containingMethod = $this->getCurrentMethod();
			$this->plugin->emitIssue(
				$this->code_base,
				$this->context,
				$this->isLikelyFalsePositive( $taint['overall'] ) ?
					'SecurityCheckTaintedOutputLikelyFalsePositive' :
					'SecurityCheckTaintedOutput',
				"Calling Method $funcName in $containingMethod that "
				. "is always unsafe (func: " . $taint['overall'] .
				" arg: $overallArgTaint) " .
				( $func ? $this->getOriginalTaintLine( $func ) : '' )
				. $overallTaintHist
			);
		}

		$newMem = memory_get_peak_usage();
		$diffMem = round( ($newMem - $oldMem ) / (1024*1024) );
		if ( $diffMem > 2 ) {
			$this->debug( __METHOD__, "Memory spike $diffMem $funcName" );
		}
		// The taint of the method call expression is the overall taint
		// of the method not counting the preserve flag plus any of the
		// taint from arguments of the right type.
		// With all the exec bits removed from args.
		$neitherPreserveOrExec = ~( SecurityCheckPlugin::PRESERVE_TAINT |
			SecurityCheckPlugin::EXEC_TAINT );
		return ( $taint['overall'] & $neitherPreserveOrExec )
			| ( $overallArgTaint & ~SecurityCheckPlugin::EXEC_TAINT );
	}

	public function visitCall( Node $node ) : int {
		return $this->visitMethodCall( $node );
	}

	public function visitVar( Node $node ) : int {
		try {
			$varName = $this->getCtxN( $node )->getVariableName();
		} catch ( IssueException $e ) {
			$this->debug( __METHOD__, "Variable is not in scope?? - " . $e->getIssueInstance() );
			return SecurityCheckPlugin::UNKNOWN_TAINT;
		}
		if ( $varName === '' ) {
			$this->debug( __METHOD__, "FIXME: Complex variable case not handled." );
			Debug::printNode( $node );
			return SecurityCheckPlugin::UNKNOWN_TAINT;
		}
		if ( !$this->context->getScope()->hasVariableWithName( $varName ) ) {
			if ( Variable::isSuperglobalVariableWithName( $varName ) ) {
				// Super globals are tainted.
				//echo "$varName is superglobal. Marking tainted\n";
				return SecurityCheckPlugin::YES_TAINT;
			}
			// Probably the var just isn't in scope yet.
			//$this->debug( __METHOD__, "No var with name \$$varName in scope (Setting Unknown taint)" );
			return SecurityCheckPlugin::UNKNOWN_TAINT;
		}
		$variableObj = $this->context->getScope()->getVariableByName( $varName );
		return $this->getTaintednessPhanObj( $variableObj );
	}

	public function visitGlobal( Node $node ) : int {
		assert( isset( $node->children['var'] ) && $node->children['var']->kind === \ast\AST_VAR );
		$varName = $node->children['var']->children['name'];
		$scope = $this->context->getScope();
		if (
			$scope->hasVariableWithName( $varName )
			&& $scope->hasGlobalVariableWithName( $varName )
		) {
			$localVar = $scope->getVariableByName( $varName );
			$globalVar = $scope->getGlobalVariableByName( $varName );
			if ( !property_exists( $globalVar, 'taintedness' ) ) {
				//echo "Setting initial taintedness for global $varName of NO\n";
				$globalVar->taintedness = SecurityCheckPlugin::NO_TAINT;
			}
			if ( property_exists( $localVar, 'taintedness' ) ) {
				// This should not happen. FIXME this is probably wrong.
				echo "\tWARNING: local var already tainted at global time.\n";
				$globalVar->taintedness |= $localVar->taintedness;
			}

			$localVar->taintedness =& $globalVar->taintedness;
			$localVar->taintednessHasExtendedScope = true;
		}
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitReturn( Node $node ) : int {
		if ( !$this->context->isInFunctionLikeScope() ) {
			$this->debug( __METHOD__, "return outside func?" );
			//Debug::printNode( $node );
			return SecurityCheckPlugin::UNKNOWN_TAINT;
		}

		$curFunc = $this->context->getFunctionLikeInScope( $this->code_base );
		$taintedness = $this->getTaintedness( $node->children['expr'] );

		$funcTaint = $this->matchTaintToParam(
			$node->children['expr'],
			$taintedness,
			$curFunc
		);

		$this->checkFuncTaint( $funcTaint );
		$this->setFuncTaint( $curFunc, $funcTaint );

		if ( $funcTaint['overall'] & SecurityCheckPlugin::YES_EXEC_TAINT ) {
			$taintSource = '';
			$pobjs = $this->getPhanObjsForNode( $node->children['expr'] );
			foreach( $pobjs as $pobj ) {
				$taintSource .= $pobj->taintedOriginalError ?? '';
			}
			if ( strlen( $taintSource ) < 200 ) {
				if ( !isset( $curFunc->taintedOriginalError ) ) {
					$curFunc->taintedOriginalError = '';
				}
				$curFunc->taintedOriginalError = substr(
					$curFunc->taintedOriginalError . $taintSource,
					0,
					250
				);
			}
		}
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	/**
	 * @suppress PhanTypeMismatchForeach
	 */
	public function visitArray( Node $node ) : int {
		$curTaint = SecurityCheckPlugin::NO_TAINT;
		foreach( $node->children as $child ) {
			assert( $child->kind === \ast\AST_ARRAY_ELEM );
			$curTaint = $this->mergeAddTaint( $curTaint, $this->getTaintedness( $child ) );
		}
		return $curTaint;
	}

	public function visitArrayElem( Node $node ) : int {
		return $this->mergeAddTaint(
			$this->getTaintedness( $node->children['value'] ),
			$this->getTaintedness( $node->children['key'] )
		);
	}

	public function visitForeach( Node $node ) : int {
		// This is handled by PreTaintednessVisitor.
		return SecurityCheckPlugin::NO_TAINT;
	}

	public function visitClassConst( Node $node ) : int {
		return SecurityCheckPlugin::NO_TAINT;
	}

	public function visitConst( Node $node ) : int {
		// We are going to assume nobody is doing stupid stuff like
		// define( "foo", $_GET['bar'] );
		return SecurityCheckPlugin::NO_TAINT;
	}

	public function visitStaticProp( Node $node ) : int {
		try {
			$props = $this->getPhanObjsForNode( $node );
		} catch ( Exception $e ) {
			$this->debug( __METHOD__, "Cannot understand static class prop. " . get_class($e) . " - {$e->getMessage()}" );
			//Debug::printNode( $node );
			return SecurityCheckPlugin::UNKNOWN_TAINT;
		}

		if ( count( $props ) > 1 ) {
			// This is unexpected.
			$this->debug( __METHOD__, "static prop has many objects" );
		}
		$taint = 0;
		foreach( $props as $prop ) {
			$taint |= $this->getTaintednessPhanObj( $prop );
		}
		return $taint;
	}


	public function visitProp( Node $node ) : int {
		try {
			$props = $this->getPhanObjsForNode( $node );
		} catch ( Exception $e ) {
			//$this->debug( __METHOD__, "Cannot understand class prop. " . get_class($e) . " - {$e->getMessage()}" );
			//Debug::printNode( $node );
			return SecurityCheckPlugin::UNKNOWN_TAINT;
		}
		if ( count( $props ) !== 1 ) {
			if (
				is_object( $node->children['expr'] ) &&
				$node->children['expr']->kind === \ast\AST_VAR &&
				$node->children['expr']->children['name'] === 'row'
			) {
				// Almost certainly a MW db result.
				// FIXME this case isn't fully handled.
				// Stuff from db probably not escaped. Most of the time.
				// Don't include serialize here due to high false positives
				// Eventhough unserializing stuff from db can be very
				// problematic if user can ever control.
				return SecurityCheckPlugin::YES_TAINT & ~SecurityCheckPlugin::SERIALIZE_TAINT;
			}
			if (
				is_object( $node->children['expr'] ) &&
				$node->children['expr']->kind === \ast\AST_VAR &&
				is_string( $node->children['expr']->children['name'] ) &&
				is_string( $node->children['prop'] )
			) {
				$this->debug( __METHOD__, "Could not find Property \$" . $node->children['expr']->children['name'] . "->" . $node->children['prop'] . "" );
			} else {
				$this->debug( __METHOD__, "Unexpected number of phan objs " . count( $props ) . "" );
				Debug::printNode( $node );
			}
			if ( count( $props ) === 0 ) {
				// Should this be NO_TAINT?
				return SecurityCheckPlugin::UNKNOWN_TAINT;
			}
		}
		$prop = $props[0];
		return $this->getTaintednessPhanObj( $prop );
	}

	/**
	 * When a prop is declared
	 */
	public function visitPropElem( Node $node ) : int {
		assert( $this->context->isInClassScope() );
		$clazz = $this->context->getClassInScope( $this->code_base );

		assert( $clazz->hasPropertyWithName( $this->code_base, $node->children['name'] ) );
		$prop = $clazz->getPropertyByNameInContext( $this->code_base, $node->children['name'], $this->context );
		// FIXME should this be NO? 
		//$this->debug( __METHOD__, "Setting taint preserve if not set yet for \$" . $node->children['name'] . "" );
		$this->setTaintedness( $prop, SecurityCheckPlugin::NO_TAINT, false );
		return SecurityCheckPlugin::INAPLICABLE_TAINT;
	}

	public function visitConditional( Node $node ) : int {
		if ( $node->children['true'] === null ) {
			// $foo ?: $bar;
			$t = $this->getTaintedness( $node->children['cond'] );
		} else {
			$t = $this->getTaintedness( $node->children['true'] );
		}
		$f = $this->getTaintedness( $node->children['false'] );
		return $this->mergeAddTaint( $t, $f );
	}

	public function visitName( Node $node ) : int {
		// FIXME I'm a little unclear on what a name is in php.
		// I think this means literal true, false, null
		// or a class name (The Foo part of Foo::bar())
		// Maybe other things too? Are class references always
		// untainted? Probably.

		return SecurityCheckPlugin::NO_TAINT;
	}

	public function visitIfElem( Node $node ) : int {
		return $this->getTaintedness( $node->children['cond'] );
	}

	public function visitUnaryOp( Node $node ) : int {
		// ~ and @ are the only two unary ops
		// that can preserve taint (others cast bool or int)
		if ( $node->flags & ( ast\flags\UNARY_BITWISE_NOT | ast\flags\UNARY_SILENCE ) === 0 ) {
			return SecurityCheckPlugin::NO_TAINT;
		}

		return $this->getTaintedness( $node->children['expr'] );
	}

	public function visitCast( Node $node ) : int {
		// Casting between an array and object maintains
		// taint. Casting an object to a string calls __toString().
		// Future TODO: handle the string case properly.
		$dangerousCasts = ast\flags\TYPE_STRING |
			ast\flags\TYPE_ARRAY |
			ast\flags\TYPE_OBJECT;

		if ( $node->flags & $dangerousCasts === 0 ) {
			return SecurityCheckPlugin::NO_TAINT;
		}
		return $this->getTaintedness( $node->children['expr'] );
	}

	/**
	 * @suppress PhanTypeMismatchForeach
	 */
	public function visitEncapsList( Node $node ) : int {
		$taint = SecurityCheckPlugin::NO_TAINT;
		foreach( $node->children as $child ) {
			$taint = $this->mergeAddTaint( $taint, $this->getTaintedness( $child ) );
		}
		return $taint;
	}

	public function visitIsset( Node $node ) : int {
		return SecurityCheckPlugin::NO_TAINT;
	}

	public function visitMagicConst( Node $node ) : int {
		return SecurityCheckPlugin::NO_TAINT;
	}

	public function visitInstanceOf( Node $node ) : int {
		return SecurityCheckPlugin::NO_TAINT;
	}
}
