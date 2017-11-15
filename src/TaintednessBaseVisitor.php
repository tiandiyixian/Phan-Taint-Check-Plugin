<?php

use Phan\AST\AnalysisVisitor;
use Phan\AST\ContextNode;
use Phan\CodeBase;
use Phan\Language\Context;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Func;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\Method;
use Phan\Language\Element\Variable;
use Phan\Language\Element\TypedElementInterface;
use Phan\Language\Element\Parameter;
use Phan\Language\UnionType;
use Phan\Language\FQSEN\FullyQualifiedFunctionLikeName;
use Phan\Plugin;
use Phan\Plugin\PluginImplementation;
use ast\Node;
use ast\Node\Decl;
use Phan\Debug;
use Phan\Language\Scope\FunctionLikeScope;
use Phan\Language\Scope\BranchScope;
use Phan\Library\Set;
use Phan\Exception\IssueException;

abstract class TaintednessBaseVisitor extends AnalysisVisitor {

	/** @var SecurityCheckPlugin */
	protected $plugin;

	private $debugOutput = null;

	public function __construct(
		CodeBase $code_base,
		Context $context,
		SecurityCheckPlugin $plugin
	) {
		parent::__construct($code_base, $context);
		$this->plugin = $plugin;
	}

	/**
	 * Change taintedness of a function/method
	 *
	 * @param FunctionInterface $func
	 * @param int[] $taint Numeric keys for each arg and an 'overall' key.
	 * @param bool $override Whether to merge taint or override
	 */
	protected function setFuncTaint( FunctionInterface $func, array $taint, bool $override = false ) {
		$curTaint = [];
		$newTaint = [];
		// What taint we're setting, to do bookkeeping about whenever
		// we add a dangerous taint.
		$mergedTaint = SecurityCheckPlugin::NO_TAINT;
		if ( property_exists( $func, 'funcTaint' ) ) {
			$curTaint = $func->funcTaint;
		}
		if ( $override ) {
			$newTaint = $taint;
		}
		foreach ( $taint as $index => $t ) {
			assert( is_int( $t ) );
			if ( !$override ) {
				$newTaint[$index] = ( $curTaint[$index] ?? 0 ) | $t;
			}
			$mergedTaint |= $t;
		}
		if ( !isset( $newTaint['overall'] ) ) {
			// FIXME, what's the right default??
			$this->debug( __METHOD__, "FIXME No overall taint specified $func" );
			$newTaint['overall'] = SecurityCheckPlugin::UNKNOWN_TAINT;
		}
		$this->checkFuncTaint( $newTaint );
		$func->funcTaint = $newTaint;


		if ( $mergedTaint & SecurityCheckPlugin::YES_EXEC_TAINT ) {
			if ( !property_exists( $func, 'taintedOriginalError' ) ) {
				$func->taintedOriginalError = '';
			}
			$func->taintedOriginalError .= $this->dbgInfo() . ';';

			if ( strlen( $func->taintedOriginalError ) > 254 ) {
				$this->debug( __METHOD__, "Too long original error! for $func" );
				$func->taintedOriginalError = substr( $func->taintedOriginalError, 0, 250 ) . '...';
			}
		}
	}

	/**
	 * It is assumed you already checked that right is tainted in some way.
	 */
	protected function mergeTaintError( TypedElementInterface $left, TypedElementInterface $right ) {
		if ( !property_exists( $left, 'taintedOriginalError' ) ) {
			$left->taintedOriginalError = '';
		}
		$left->taintedOriginalError .= $right->taintedOriginalError ?? '';

		if ( strlen( $left->taintedOriginalError ) > 254 ) {
			$this->debug( __METHOD__, "Too long original error! for $left" );
			$left->taintedOriginalError = substr( $left->taintedOriginalError, 0, 250 ) . '...';
		}
	}


	/**
	 * Change the taintedness of a variable
	 *
	 * @param TypedElementInterface $variableObj The variable in question
	 * @param int $taintedness One of the class constants
	 * @param bool $override Override taintedness or just take max.
	 */
	protected function setTaintedness( TypedElementInterface $variableObj, int $taintedness, $override = true ) {
		//$this->debug( __METHOD__, "begin for \$" . $variableObj->getName() . " <- $taintedness (override=$override)" );

		assert( $taintedness >= 0, $taintedness );

		if ( $variableObj instanceof FunctionInterface ) {
			// FIXME what about closures?
			throw new Exception( "Must use setFuncTaint for functions" );
		}

		if ( property_exists( $variableObj, 'taintednessHasOuterScope' )
			|| !($this->context->getScope() instanceof FunctionLikeScope)
		) {
//$this->debug( __METHOD__, "\$" . $variableObj->getName() . " has outer scope - " . get_class( $this->context->getScope()) . "" );
			// If the current context is not a FunctionLikeScope, then
			// it might be a class, or an if branch, or global. In any case
			// its probably a non-local variable (or in the if case, code
			// that may not be executed).
			//

			if ( !property_exists( $variableObj, 'taintednessHasOuterScope' )
				&& ($this->context->getScope() instanceof BranchScope)
			) {
//echo __METHOD__ . "in a branch\n";
				$scope = $this->context->getScope();
				do {
					//echo __METHOD__ . " getting parent scope\n";
					$scope = $scope->getParentScope();
				} while( $scope instanceof BranchScope );
				if ( $scope->hasVariableWithName( $variableObj->getName() ) ) {
					$parentVarObj = $scope->getVariableByName( $variableObj->getName() );

					if ( !property_exists( $parentVarObj, 'taintedness' ) ) {
						//echo __METHOD__ . " parent scope for {$variableObj->getName()} has no taint\n";
						$parentVarObj->taintedness = $taintedness;
					} else {
						$parentVarObj->taintedness = $this->mergeAddTaint( $parentVarObj->taintedness, $taintedness );
					}
					$variableObj->taintedness =& $parentVarObj->taintedness;

					$methodLinks = $parentVarObj->taintedMethodLinks ?? new Set;
					$variableObjLinks = $variableObj->taintedMethodLinks ?? new Set;
					$variableObj->taintedMethodLinks = $methodLinks->union( $variableObjLinks );
					$parentVarObj->taintedMethodLinks =& $variableObj->taintedMethodLinks;
					$combinedOrig = ( $variableObj->taintedOriginalError ?? '' ) . ( $parentVarObj->taintedOriginalError ?? '' );
					if ( strlen( $combinedOrig ) > 254 ) {
						$this->debug( __METHOD__, "Too long original error! $variableObj" );
						$combinedOrig = substr( $combinedOrig, 0, 250 ) . '...';
					}
					$variableObj->taintedOriginalError = $combinedOrig;
					$parentVarObj->taintedOriginalError =& $variableObj->taintedOriginalError;

				} else {
					$this->debug( __METHOD__, "var {$variableObj->getName()} does not exist outside branch!" );
				}
			}
			// This may not be executed, so it can only increase
			// taint level, not decrease.
			// Future todo: In cases of if...else where all cases covered,
			// should try to merge all branches ala ContextMergeVisitor.
			if ( property_exists( $variableObj, 'taintedness' ) ) {
				$variableObj->taintedness = $this->mergeAddTaint( $variableObj->taintedness, $taintedness );
			} else {
				$variableObj->taintedness = $taintedness;
			}
		} else {
//echo __METHOD__ . " \${$variableObj->getName()} is local variable\n";
			// This must be executed, so it can overwrite taintedness.
			$variableObj->taintedness = $override ?
				$taintedness :
				$this->mergeAddTaint(
					$variableObj->taintedness ?? 0, $taintedness
				);
		}

		if ( $this->isExecTaint( $taintedness ) || $this->isYesTaint( $taintedness ) ) {
			if ( !property_exists( $variableObj, 'taintedOriginalError' ) ) {
				$variableObj->taintedOriginalError = '';
			}
			$variableObj->taintedOriginalError .= $this->dbgInfo() . ';';

			if ( strlen( $variableObj->taintedOriginalError ) > 254 ) {
				$this->debug( __METHOD__, "Too long original error! $variableObj" );
				$variableObj->taintedOriginalError = substr( $variableObj->taintedOriginalError, 0, 250 ) . '...';
			}
		}
	}

	/**
	 * Merge two taint values together
	 *
	 * @param int $oldTaint One of the class constants
	 * @param int $newTaint One of the class constants
	 * @return int The merged taint value
	 */
	protected function mergeAddTaint( int $oldTaint, int $newTaint ) {
		// TODO: Should this clear UNKNOWN_TAINT if its present
		// only in one of the args?
		return $oldTaint | $newTaint;
	}

	/**
	 * This is also for methods and other function like things
	 *
	 * @return int[] Array with "overall" key, and numeric keys.
	 *   The overall key specifies what taint the function returns
	 *   irrespective of its arguments. The numeric keys are how
	 *   each individual argument affects taint.
	 *
	 *   For 'overall': the EXEC flags mean a call does evil regardless of args
	 *                  the TAINT flags are what taint the output has
	 *   For numeric keys: EXEC flags for what taints are unsafe here
	 *                     TAINT flags for what taint gets passed through func.
	 *   If func has an arg that is mssing from array, then it should be
	 *   treated as TAINT_NO if its a number or bool. TAINT_YES otherwise.
	 */
	protected function getTaintOfFunction( FunctionInterface $func ) {
		$funcName = $func->getFQSEN();
		$taint = null;
		$taint = $this->getBuiltinFuncTaint( $funcName );
		if ( $taint !== null ) {
			return $taint;
		}
		if ( $func->isInternal() ) {
			// Built in php.
			// Assume that anything really dangerous we've already
			// hardcoded. So just preserve taint
			return [ 'overall' => SecurityCheckPlugin::PRESERVE_TAINT ];
		}
		if ( property_exists( $func, 'funcTaint' ) ) {
			$taint = $func->funcTaint;
		} else {
			// Ensure we don't indef loop.
			if (
				!$func->isInternal() &&
				( !$this->context->isInFunctionLikeScope() ||
				$func->getFQSEN() !== $this->context->getFunctionLikeFQSEN() )
			) {
				// $this->debug( __METHOD__, "no taint info for func $func" );
				try {
					$func->analyze( $func->getContext(), $this->code_base );
				} catch( Exception $e ) {
					$this->debug( __METHOD__, "Error" . $e->getMessage() . "\n" );
				}
				// $this->debug( __METHOD__, "updated taint info for $func" );
				// var_dump( $func->funcTaint ?? "NO INFO" );
				if ( property_exists( $func, 'funcTaint' ) ) {
					$this->checkFuncTaint( $func->funcTaint );
					return $func->funcTaint;
				}
			}
			// TODO: Maybe look at __toString() if we are at __construct().
			// FIXME this could probably use a second look.

			// If we haven't seen this function before, first of all
			// check the return type. If it (e.g.) returns just an int,
			// its probably safe.
			$taint = [ 'overall' => $this->getTaintByReturnType( $func->getUnionType() ) ];
			/*if ( $taint === SecurityCheckPlugin::UNKNOWN_TAINT ) {
			*	//Otherwise, if its unknown, assume that
			*	// the function depends only on its arguments (unclear how
			*	// good an assumption this is. Does it make more sense to
			*	// assume its safe until). Except we don't.
			*	$taint = SecurityCheckPlugin::PRESERVE_TAINT;
			}*/
			//echo "No taint for method $funcName - now $taint\n";
		}
		$this->checkFuncTaint( $taint );
		return $taint;
	}

	/**
	 * Only use as a fallback
	 */
	protected function getTaintByReturnType( UnionType $types ) : int {
		$taint = SecurityCheckPlugin::NO_TAINT;

		$typelist = $types->getTypeSet();
		if ( count( $typelist ) === 0 ) {
			//$this->debug( __METHOD__, "Setting type unknown due to no type info." );
			return SecurityCheckPlugin::UNKNOWN_TAINT;
		}
		foreach( $types->getTypeSet() as $type ) {
			switch( $type->getName() ) {
			case 'int':
			case 'float':
			case 'bool':
			case 'true':
			case 'null':
			case 'void':
				$taint = $this->mergeAddTaint( $taint, SecurityCheckPlugin::NO_TAINT );
				break;
			default:
				// This means specific class.
				// TODO - maybe look up __toString() method.
			case 'string':
			case 'closure':
			case 'callable':
			case 'array':
			case 'object':
			case 'resource':
			case 'mixed':
				// TODO If we have a specific class, maybe look at __toString()
				//$this->debug( __METHOD__, "Taint set unknown due to type '$type'." );
				$taint = $this->mergeAddTaint( $taint, SecurityCheckPlugin::UNKNOWN_TAINT );
				break;
			}
		}
		return $taint;
	}

	/**
	 * @param FullyQualifiedFunctionLikeName $fqsen Function to check
	 * @return null|array Null if no info, otherwise the taint for the function
	 */
	protected function getBuiltinFuncTaint( FullyQualifiedFunctionLikeName $fqsen ) {
		$taint = $this->plugin->getBuiltinFuncTaint( $fqsen );
		if ( $taint !== null ) {
			$this->checkFuncTaint( $taint );
		}
		return $taint;
	}

	protected function getCurrentMethod() {
		return $this->context->isInFunctionLikeScope() ?
			$this->context->getFunctionLikeFQSEN() : '[no method]';
	}

	/**
	 * Get the taintedness of something from the AST tree.
	 *
	 * @warning This does not take into account preexisting taint
	 *  unless you provide it with a Phan object (Not an AST node).
	 *
	 * FIXME maybe it should try and turn into phan object.
	 * @param Mixed $expr An expression from the AST tree.
	 * @return int
	 */
	protected function getTaintedness( $expr ) : int {
		$type = gettype( $expr );
		switch ( $type ) {
		case "string":
		case "boolean":
		case "integer":
		case "double":
		case "NULL":
			// simple literal
			return SecurityCheckPlugin::NO_TAINT; 
		case "object":
			if ( $expr instanceof Node ) {
				return $this->getTaintednessNode( $expr );
			} elseif( $expr instanceof TypedElementInterface ) {
				// echo __METHOD__ . "FIXME, do we want this interface here?\n";
				return $this->getTaintednessPhanObj( $expr );
			}
			// fallthrough
		case "resource":
		case "unknown type":
		case "array":
		default:
			throw new Exception( "wtf - $type" );

		}
	}

	protected function getTaintednessNode( Node $node ) : int {
		//Debug::printNode( $node );
		$r = (new TaintednessVisitor($this->code_base, $this->context, $this->plugin))(
			$node
		);
		assert( $r >= 0, $r );
		return $r;
	}

	protected function getTaintednessPhanObj( TypedElementInterface $variableObj ) : int {
		$taintedness = SecurityCheckPlugin::UNKNOWN_TAINT;
		if ( $variableObj instanceof FunctionInterface ) {
			throw new Exception( "This method cannot be used with methods" );
		}
		if ( property_exists( $variableObj, 'taintedness' ) ) {
			$taintedness = $variableObj->taintedness;
			//echo "$varName has taintedness $taintedness due to last time\n";
		} else {
			$type = $variableObj->getUnionType(); 
			$taintedness = $this->getTaintByReturnType( $type );
			//echo $this->dbgInfo() . " \$" . $variableObj->getName() . " first sight. taintedness set to $taintedness due to type $type\n";
		}
		assert( is_int( $taintedness ) && $taintedness >= 0 );
		return $taintedness;
	}

	protected function getCtxN( Node $node ) {
		return new ContextNode(
			$this->code_base,
			$this->context,
			$node
		);
	}

	/**
	 * Given a node, return the Phan variable objects that
	 * corespond to that node. Note, this will ignore
	 * things like method calls (for now at least).
	 *
	 * @throws Exception (Not sure what circumstances)
	 *
	 * TODO: Maybe this should be a visitor class instead(?)
	 *
	 * This method is a little confused, because sometimes we only
	 * want the objects that materially contribute to taint, and
	 * other times we want all the objects.
	 * e.g. Should foo( $bar ) return the $bar variable object?
	 *  What about the foo function object?
	 *
	 * @suppress PhanTypeMismatchForeach No idea why its confused
	 * @suppress PhanUndeclaredMethod it checks method_exists()
	 */
	protected function getPhanObjsForNode( Node $node, $all = false ) {
		$cn = $this->getCtxN( $node );

		switch( $node->kind ) {
			case \ast\AST_PROP:
			case \ast\AST_STATIC_PROP:
				try {
					return [ $cn->getProperty( $node->children['prop'] ) ];
				} catch( Exception $e ) {
					try {
						// There won't be an expr for static prop.
						if ( isset( $node->children['expr'] ) ) {
							$cnClass = $this->getCtxN( $node->children['expr'] );
							if ( $cnClass->getVariableName() === 'row' ) {
								// Its probably a db row, so ignore.
								// FIXME, we should handle the
								// db row situation much better.
								return [];
							}
						}
					} catch( IssueException $e ) {
						$this->debug( __METHOD__, "Cannot determine property or var name [1] (Maybe don't know what class) - " . $e->getIssueInstance() );
						return [];
					} catch( Exception $e ) {
						$this->debug( __METHOD__, "Cannot determine property or var name [2] (Maybe don't know what class) - " . get_class( $e ) . $e->getMessage() );
						return [];
					}
					$this->debug( __METHOD__, "Cannot determine property [3] (Maybe don't know what class) - " . ( method_exists( $e, 'getIssueInstance' ) ? $e->getIssueInstance() : get_class( $e ) . $e->getMessage() ) );
					return [];
				}
			case \ast\AST_VAR:
				try {
					if ( Variable::isHardcodedGlobalVariableWithName( $cn->getVariableName() ) ) {

						return [];
					} else {
						return [ $cn->getVariable() ];
return [];
					}
				} catch ( IssueException $e ) {
					$this->debug( __METHOD__, "variable not in scope?? " . $e->getIssueInstance() );
					return [];
				} catch ( Exception $e ) {
					$this->debug( __METHOD__, "variable not in scope?? " . get_class( $e ) . $e->getMessage() );
					return [];
				}
			case \ast\AST_LIST:
			case \ast\AST_ENCAPS_LIST:
			case \ast\AST_ARRAY:
				$results = [];
				foreach( $node->children as $child ) {
					if ( !is_object( $child ) ) {
						continue;
					}
					$results = array_merge( $this->getPhanObjsForNode( $child ), $results );
				}
				return $results;
			case \ast\AST_ARRAY_ELEM:
				$results = [];
				if ( is_object( $node->children['key'] ) ) {
					$results = array_merge(
						$this->getPhanObjsForNode( $node->children['key'] ),
						$results
					);
				}
				if ( is_object( $node->children['value'] ) ) {
					$results = array_merge(
						$this->getPhanObjsForNode( $node->children['value'] ),
						$results
					);
				}
				return $results;
			case \ast\AST_CAST:
				// Future todo might be to ignore casts to ints, since
				// such things should be safe. Unclear if that makes
				// sense in all circumstances.
				if ( is_object( $node->children['expr'] ) ) {
					return $this->getPhanObjsForNode( $node->children['expr'] );
				}
				return [];
			case \ast\AST_DIM:
				// For now just consider the outermost array.
				// FIXME. doesn't handle tainted array keys!
				return $this->getPhanObjsForNode( $node->children['expr'] );
			case \ast\AST_UNARY_OP:
				$var = $node->children['expr'];
				return $var instanceof Node ? $this->getPhanObjsForNode( $var ) : [];
			case \ast\AST_BINARY_OP:
				$left = $node->children['left'];
				$right = $node->children['right'];
				$leftObj = $left instanceof Node ? $this->getPhanObjsForNode( $left ) : [];
				$rightObj = $right instanceof Node ? $this->getPhanObjsForNode( $right ) : [];
				return array_merge( $leftObj, $rightObj );
			case \ast\AST_CONDITIONAL:
				$t = $node->children['true'];
				$f = $node->children['false'];
				$tObj = $t instanceof Node ? $this->getPhanObjsForNode( $t ) : [];
				$fObj = $f instanceof Node ? $this->getPhanObjsForNode( $f ) : [];
				return array_merge( $tObj, $fObj );
			case \ast\AST_CONST:
			case \ast\AST_CLASS_CONST:
			case \ast\AST_MAGIC_CONST:
			case \ast\AST_ISSET:
			case \ast\AST_NEW:
			// For now we don't do methods, only variables
			// Also don't do args to function calls.
			// Unclear if this makes sense.
				return [];
			case \ast\AST_CALL:
			case \ast\AST_STATIC_CALL:
			case \ast\AST_METHOD_CALL:
				if ( !$all ) {
					return [];
				}
				try {
					$ctxNode = $this->getCtxN( $node );
					if ( $node->kind === \ast\AST_CALL ) {
						if ( $node->children['expr']->kind !== \ast\AST_NAME ) {
							return [];
						}
						$func = $ctxNode->getFunction( $node->children['expr']->children['name'] );
					} else {
						$methodName = $node->children['method'];
						$func = $ctxNode->getMethod(
							$methodName,
							$node->kind === \ast\AST_STATIC_CALL
						);
					}
					$args = $node->children['args']->children;
					$pObjs = [ $func ];
					foreach( $args as $arg ) {
						if ( !( $arg instanceof Node ) ) {
							continue;
						}
						$pObjs = array_merge(
							$pObjs,
							$this->getPhanObjsForNode( $arg )
						);
					}
					return $pObjs;
				} catch ( Exception $e ) {
					// Something non-simple
					// Future todo might be to still return
					// arguments in this case.
					return [];
				}
			default:
				//Debug::printNode( $node );
				// This should really be a visitor that recurses into
				// things.
				echo  __METHOD__ . $this->dbgInfo() . " FIXME unhandled case" . \ast\get_kind_name( $node->kind ) . "\n";
				return [];
		}
	}

	/**
	 * Get the current filename and line.
	 *
	 * @return string path/to/file +linenumber
	 */
	protected function dbgInfo() {
		// Using a + instead of : so that I can just copy and paste
		// into a vim command line.
		return ' ' . $this->context->getFile() . ' +' . $this->context->getLineNumberStart();
	}

	/**
	 * Link together a Method and its parameters
	 *
	 * The idea being if the method gets called with something evil
	 * later, we can traceback anything it might affect
	 *
	 * @suppress PhanTypeMismatchProperty
	 */
	protected function linkParamAndFunc( Variable $param, FunctionInterface $func, int $i ) {
		if ( !( $param instanceof Variable ) ) {
			// Probably a PassByReferenceVariable.
			// TODO, handling of PassByReferenceVariable probably wrong here.
			$this->debug( __METHOD__, "Called on a non-variable \$" . $param->getName() . " of type " . get_class( $param ) . ". May be handled wrong." );
		}
		if ( !property_exists( $func, 'taintedVarLinks' ) ) {
			$func->taintedVarLinks = [];
		}
		if ( !isset( $func->taintedVarLinks[$i] ) ) {
			$func->taintedVarLinks[$i] = new Set;
		}
		if ( !property_exists( $param, 'taintedMethodLinks' ) ) {
			// This is a map of FunctionInterface -> int[]
			$param->taintedMethodLinks = new Set;
		}

		$func->taintedVarLinks[$i]->attach( $param );
		if ( $param->taintedMethodLinks->contains( $func ) ) {
			$data = $param->taintedMethodLinks[$func];
			$data[$i] = true;
		} else {
			$param->taintedMethodLinks[$func] = [ $i => true ];
		}
	}

	protected function mergeTaintDependencies( TypedElementInterface $lhs, TypedElementInterface $rhs ) {
		$taintLHS = $this->getTaintedness( $lhs );
		$taintRHS = $this->getTaintedness( $rhs );
		/********************
		FIXME what was this check about. Does it make sense as an
		error condition??
		// LHS may already be tainted by something earlier.
		if (
			$taintLHS < SecurityCheckPlugin::PRESERVE_TAINT ||
			$taintRHS !== SecurityCheckPlugin::PRESERVE_TAINT
		) {
			$this->debug( __METHOD__, "FIXME merging dependencies where LHS and RHS are not both preserved taint. lhs=$taintLHS; rhs=$taintRHS" );
		} */

		if ( $taintRHS & SecurityCheckPlugin::YES_EXEC_TAINT ) {
			$this->mergeTaintError( $lhs, $rhs );
		}

		if ( !property_exists( $rhs, 'taintedMethodLinks' ) ) {
			// $this->debug( __METHOD__, "FIXME no back links on preserved taint" );
			return;
		}

		if ( !property_exists( $lhs, 'taintedMethodLinks' ) ) {
			$lhs->taintedMethodLinks = new Set;
		}

		// So if we have $a = $b;
		// First we find out all the methods that can set $b
		// Then we add $a to the list of variables that those methods can set.
		// Last we add these methods to $a's list of all methods that can set it.
		foreach ( $rhs->taintedMethodLinks as $method ) {
			$paramInfo = $rhs->taintedMethodLinks[$method];
			foreach( $paramInfo as $index => $_ ) {
				assert( property_exists( $method, 'taintedVarLinks' ) );
				assert( isset( $method->taintedVarLinks[$index] ) );

				$method->taintedVarLinks[$index]->attach( $lhs );
			}
			if ( isset( $lhs->taintedMethodLinks[$method] ) ) {
				$lhs->taintedMethodLinks[$method] += $paramInfo;
			}
			$lhs->taintedMethodLinks[$method] = $paramInfo;
		}
	}

	/**
	 * If you do something like echo $this->foo;
	 * This method is called to make all things that set $this->foo
	 * as TAINT_EXEC.
	 *
	 * TODO delete all dependencies as no longer needed.
	 */
	protected function markAllDependentMethodsExec(
		TypedElementInterface $var,
		int $taint = SecurityCheckPlugin::EXEC_TAINT
	) {
		// FIXME. Does this check make sense?
		// should it also check if it has any of the YES_TAINT flags?

		//echo __METHOD__ . $this->dbgInfo() . "Setting all methods dependent on $var as exec\n";
		if ( !property_exists( $var, 'taintedMethodLinks' ) ) {
			//$this->debug( __METHOD__, "no backlinks on $var" );
			return;
		}

		$oldMem = memory_get_peak_usage();

		foreach( $var->taintedMethodLinks as $method ) {
			$paramInfo = $var->taintedMethodLinks[$method];
			$paramTaint = [ 'overall' => SecurityCheckPlugin::NO_TAINT ];
			foreach( $paramInfo as $i => $_ ) {
				$paramTaint[$i] = $taint;
				//$this->debug( __METHOD__ , "Setting method $method arg $i as $taint due to depenency on $var" );
			}
			$this->setFuncTaint( $method, $paramTaint );
		}
		$curVarTaint = $this->getTaintedness( $var );
		$newTaint = $this->mergeAddTaint( $curVarTaint, $taint );
		$this->setTaintedness( $var, $newTaint );

		$newMem = memory_get_peak_usage();
		$diffMem = round( ($newMem - $oldMem ) / (1024*1024) );
		if ( $diffMem > 2 ) {
			$this->debug( __METHOD__, "Memory spike $diffMem for $var" );
		}
		// FIXME delete links
	}

	/**
	 * This happens when someone call foo( $evilTaintedVar );
	 *
	 * It makes sure that anything that foo sets will become tainted.
	 */
	protected function markAllDependentVarsYes( FunctionInterface $method, int $i ) {
		if ( $method->isInternal() ) {
			return;
		}
		if (
			!property_exists( $method, 'taintedVarLinks' )
			|| !isset( $method->taintedVarLinks[$i] )
		) {
			echo __METHOD__ . $this->dbgInfo() . "returning early no backlinks\n";
			return;
		}
		$oldMem = memory_get_peak_usage();
		//echo __METHOD__ . $this->dbgInfo() . "Setting all vars depending on $method as tainted\n";
		foreach ( $method->taintedVarLinks[$i] as $var ) {
			$curVarTaint = $this->getTaintedness( $var );
			$newTaint = $this->mergeAddTaint( $curVarTaint, SecurityCheckPlugin::YES_TAINT );
			//echo __METHOD__ . $this->dbgInfo() . "Setting $var as $newTaint due to dependency on $method\n";
			$this->setTaintedness( $var, $newTaint );
		}
		// Maybe delete links??
		$newMem = memory_get_peak_usage();
		$diffMem = round( ($newMem - $oldMem ) / (1024*1024) );
		if ( $diffMem > 2 ) {
			$this->debug( __METHOD__, "Memory spike $diffMem for $var" );
		}
	}

	/**
	 * @param int taint
	 * @return bool If the variable has known (non-execute taint)
	 */
	protected function isYesTaint( $taint ) {
		return ( $taint & SecurityCheckPlugin::YES_TAINT ) !== 0;
	}

	/**
	 * @param int taint
	 * @return bool If the variable has any exec taint
	 */
	protected function isExecTaint( $taint ) {
		return ( $taint & SecurityCheckPlugin::EXEC_TAINT ) !== 0;
	}

	/**
	 * Convert the yes taint bits to corresponding exec taint bits.
	 *
	 * Any UNKNOWN_TAINT or INAPLICABLE_TAINT is discarded.
	 *
	 * @param int $taint
	 * @return int The converted taint
	 */
	protected function yesToExecTaint( int $taint ) : int {
		return ( $taint & SecurityCheckPlugin::YES_TAINT ) << 1;
	}

	/**
	 * Convert exec to yes taint
	 *
	 * Special flags like UNKNOWN or INAPLICABLE are discarded.
	 */
	protected function execToYesTaint( int $taint ) : int {
		return ( $taint & SecurityCheckPlugin::EXEC_TAINT ) >> 1;
	}

	/**
	 * Whether merging the rhs to lhs is an safe operation
	 *
	 * @param int $lhs Taint of left hand side
	 * @param int $rhs Taint of right hand side
	 * @return bool Is it safe
	 */
	protected function isSafeAssignment( $lhs, $rhs ) {
		$adjustRHS = $this->yesToExecTaint( $rhs );
		//$this->debug( __METHOD__, "lhs=$lhs; rhs=$rhs, adjustRhs=$adjustRHS" ); 
		return ( $adjustRHS & $lhs ) === 0 &&
			!(
				( $lhs & SecurityCheckPlugin::EXEC_TAINT ) &&
				( $rhs & SecurityCheckPlugin::UNKNOWN_TAINT )
			);
	}

	/**
	 * Is taint likely a false positive
	 * @param $taint
	 *
	 * Taint is a false positive if it has the unknown flag but
	 * none of the yes flags.
	 */
	protected function isLikelyFalsePositive( $taint ) {
		return ( $taint & SecurityCheckPlugin::UNKNOWN_TAINT ) !== 0
			&& ( $taint & SecurityCheckPlugin::YES_TAINT ) === 0;
	}

	/**
	 * Get the line number of the original cause of taint.
	 *
	 * @param TypedElementInterface|Node $element
	 * @return string
	 */
	protected function getOriginalTaintLine( $element ) {
		$line = '';
		if ( $element instanceof TypedElementInterface ) {
			if ( property_exists( $element, 'taintedOriginalError' ) ) {
				$line = $element->taintedOriginalError;
			}
		} elseif ( $element instanceof Node ) {
			$pobjs = $this->getPhanObjsForNode( $element );
			foreach( $pobjs as $elem ) {
				if ( property_exists( $elem, 'taintedOriginalError' ) ) {
					$line .= $elem->taintedOriginalError;
				}
			}
			if ( $line === '' ) {
				// try to dig deeper.
				// This will also include method calls and whatnot.
				// FIXME should we always do this? Is it too spammy.
				$pobjs = $this->getPhanObjsForNode( $element, true );
				foreach( $pobjs as $elem ) {
					if ( property_exists( $elem, 'taintedOriginalError' ) ) {
						$line .= $elem->taintedOriginalError;
					}
				}
			}
		} else {
			throw new Exception( $this->dbgInfo() . "invalid parameter" );
		}
		assert( strlen( $line ) < 8096, " taint error too long $line" );
		if ( $line ) {
			$line = substr( $line, 0, strlen( $line ) - 1 );
			return " (Caused by:$line)";
		} else {
			return '';
		}
	}

	/**
	 * Match an expressions taint to func arguments
	 *
	 * Given an ast expression (node, or literal value) try and figure
	 * out which of the current function's parameters its taint came
	 * from.
	 *
	 * @param Mixed $node Either a Node or a string, int, etc. The expression
	 * @param int $taintedness The taintedness in question
	 * @param FunctionInterface $func The function/method we are in.
	 * @return Array numeric keys for each parameter taint and 'overall' key
	 */
	protected function matchTaintToParam(
		$node,
		int $taintedness,
		FunctionInterface $curFunc
	) : array {
		assert( $taintedness >= 0, $taintedness );
		if ( !is_object( $node ) ) {
			assert( $taintedness === SecurityCheckPlugin::NO_TAINT );
			return [ 'overall' => $taintedness ];
		}

		// Try to match up the taintedness of the return expression
		// to which parameter caused the taint. This will only work
		// in relatively simple cases.
		// $taintRemaining is any taint we couldn't attribute.
		$taintRemaining = $taintedness;
		// $paramTaint is taint we attribute to each param
		$paramTaint = [];
		// $otherTaint is taint contributed by other things.
		$otherTaint = SecurityCheckPlugin::NO_TAINT;

		$pobjs = $this->getPhanObjsForNode( $node );
		foreach ( $pobjs as $pobj ) {
			$pobjTaintContribution = $this->getTaintedness( $pobj );
			// $this->debug( __METHOD__, "taint for $pobj is $pobjTaintContribution" );
			$links = $pobj->taintedMethodLinks ?? null;
			if ( !$links ) {
				// No method links.
				 $this->debug( __METHOD__, "no method links for " .$curFunc->getFQSEN() );
				$otherTaint |= $pobjTaintContribution;
				$taintRemaining &= ~$pobjTaintContribution;
				continue;
			}

			/** @var Set $links Its not a normal array */
			foreach ( $links as $func ) {
				/** @var $paramInfo array Array of int -> true */
				$paramInfo = $links[$func];
				if ( (string)($func->getFQSEN()) === (string)($curFunc->getFQSEN()) ) {
					foreach ( $paramInfo as $i => $_ ) {
						if ( !isset( $paramTaint[$i] ) ) {
							$paramTaint[$i] = 0;
						}
						$paramTaint[$i] = $pobjTaintContribution;
						$taintRemaining &= ~$pobjTaintContribution;
					}
				} else {
					$taintRemaining &= ~$pobjTaintContribution;
					$otherTaint |= $pobjTaintContribution;
				}
			}
		}
		$paramTaint['overall'] = ( $otherTaint | $taintRemaining ) &
			$taintedness;
		// $this->debug( __METHOD__, " otherTaint $otherTaint, taintRemaining $taintRemaining, taintedness = $taintedness, overall " . $paramTaint['overall'] );
		return $paramTaint;
	}

	/**
	 * Output a debug message to stdout.
	 *
	 * @param string $method __METHOD__ in question
	 * @param string $msg debug message
	 */
	protected function debug( $method, $msg ) {
		if ( !$this->debugOutput ) {
			$errorOutput = getenv( "SECCHECK_DEBUG" );
			if ( $errorOutput ) {
				$this->debugOutput = fopen( $errorOutput, "w" );
			}
		}
		$line = $method . "\33[1m" . $this->dbgInfo() . " \33[0m" . $msg .     "\n";
		if ( $this->debugOutput && $this->debugOutput !== '-' ) {
			fwrite(
				$this->debugOutput,
				$line
			);
		} elseif ( $this->debugOutput === '-' ) {
			echo $line;
		}
	}

	/**
	 * Make sure func taint is well formed
	 *
	 * @param array the taint of a function
	 */
	protected function checkFuncTaint( array $taint ) {
		assert(
			isset( $taint['overall'] )
			&& is_int( $taint['overall'] )
			&& $taint >= 0,
			"Overall taint is wrong " . $this->dbgInfo() . ($taint['overall'] ?? 'unset' )
		);

		foreach( $taint as $i => $t ) {
			assert( is_int( $t ) && $t >= 0, "Taint index $i wrong $t" . $this->dbgInfo() );
		}
	}
}