<?php

/**
 * ProcessWire Base Class "Wire"
 *
 * Classes that descend from this have access to a $fuel property and fuel() method containing all of ProcessWire's objects. 
 * Descending classes can specify which methods should be "hookable" by precending the method name with 3 underscores: "___". 
 * This class provides the interface for tracking changes to object properties. 
 * message() and error() methods are provided for this class to provide any text notices. 
 *
 * ProcessWire 2.x 
 * Copyright (C) 2010 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */

abstract class Wire implements TrackChanges {

	/**
	 * Fuel holds references to other ProcessWire system objects. It is an instance of the Fuel class. 
	 *
	 */
	protected static $fuel = null;

	/**
	 * When a hook is specified, there are a few options which can be overridden: This array outlines those options and the defaults. 
	 *
	 * - type: may be either 'method' or 'property'. If property, then it will respond to $obj->property rather than $obj->method().
	 * - before: execute the hook before the method call? Not applicable if 'type' is 'property'. 
	 * - after: execute the hook after the method call? (allows modification of return value). Not applicable if 'type' is 'property'.
	 * - priority: a number determining the priority of a hook, where lower numbers are executed before higher numbers. 
	 * - allInstances: attach the hook to all instances of this object? (store in staticHooks rather than localHooks). Set automatically, but you may still use in some instances.
	 * - fromClass: the name of the class containing the hooked method, if not the object where addHook was executed. Set automatically, but you may still use in some instances.
	 *
	 */
	protected static $defaultHookOptions = array(
		'type' => 'method', 
		'before' => false,
		'after' => true, 
		'priority' => 100,
		'allInstances' => false,
		'fromClass' => '', 
		);

	/**
	 * Static hooks are applicable to all instances of the descending class. 
	 *
	 * This array holds references to those static hooks, and is shared among all classes descending from Wire. 
	 * It is for internal use only. See also self::$defaultHookOptions[allInstances].
	 *
	 */
	protected static $staticHooks = array();


	/**
	 * Hooks that are local to this instance of the class only. 
	 *
	 */ 
	protected $localHooks = array();

	/**
	 * A static cache of all hook method/property names for an optimization.
	 *
	 * Hooked methods end with '()' while hooked properties don't. 
	 *
	 * This does not distinguish which class it was added to or whether it was removed. 
	 * It is only to gain some speed in our __get and __call methods.
	 *
	 */
	protected static $hookMethodCache = array();


	/**
	 * Cached name of this class from the className() method
	 *
	 */
	private $className = '';

	/**
	 * Is change tracking turned on? 
	 *
	 */
	protected $trackChanges = false; 

	/**
	 * Array containing the names of properties that were changed while change tracking was ON. 
	 *
	 */
	protected $changes = array();

	/**
	 * Add fuel to all classes descending from Wire
	 *
	 * @param string $name 
	 * @param mixed $value 
	 *
	 */
	public static function setFuel($name, $value) {
		if(is_null(self::$fuel)) self::$fuel = new Fuel();
		self::$fuel->set($name, $value); 
	}

	/**
	 * Get the Fuel specified by $name or NULL if it doesn't exist
	 *
	 * @param string $name
	 * @return mixed|null
	 *
	 */
	public static function getFuel($name) {
		return self::$fuel->$name;
	}

	/**
	 * Returns an iterable Fuel object of all Fuel currently loaded
	 *
	 * @return Fuel
	 *
	 */
	public static function getAllFuel() {
		return self::$fuel;
	}

	/**
	 * Get the Fuel specified by $name or NULL if it doesn't exist
	 *
	 * Alias for getFuel()
	 *
	 * @param string $name
	 * @return mixed|null
	 *
	 */
	public function fuel($name) {
		return self::$fuel->$name;
	}

	/**
	 * Return this object's class name
	 *
	 * Note that it caches the class name in the $className object property to reduce overhead from calls to get_class().
	 *
	 * @return string
	 *
	 */
	public function className() {
		if(!$this->className) $this->className = get_class($this); 
		return $this->className; 
	}

	/**
	 * Get an object property by direct reference or NULL if it doesn't exist
	 *
	 * If not overridden, this is primarily used as a shortcut for the fuel() method. 
	 *
	 * @param string $name
	 * @return mixed|null
	 *
	 */
	public function __get($name) {

		if($name == 'fuel') return self::getAllFuel();
		if($name == 'className') return $this->className();
		if(!is_null(self::$fuel) && !is_null(self::$fuel->$name)) return self::$fuel->$name; 

		if(self::isHooked($name)) { // potential property hook
			$result = $this->runHooks($name, array(), 'property'); 
			return $result['return'];
		}

		return null;
	}

	/**
	 * Unless overridden, classes descending from Wire return their class name when typecast as a string
	 *
	 */
	public function __toString() {
		return $this->className();
	}

	/**
	 * Provides the gateway for calling hooks in ProcessWire
	 * 
	 * When a non-existant method is called, this checks to see if any hooks have been defined and sends the call to them. 
	 * 
	 * Hooks are defined by preceding the "hookable" method in a descending class with 3 underscores, like __myMethod().
	 * When the API calls $myObject->myMethod(), it gets sent to $myObject->___myMethod() after any 'before' hooks have been called. 
	 * Then after the ___myMethod() call, any "after" hooks are then called. "after" hooks have the opportunity to change the return value.
	 *
	 * Hooks can also be added for methods that don't actually exist in the class, allowing another class to add methods to this class. 
	 *
	 * See the Wire::runHooks() method for the full implementation of hook calls.
	 *
	 * @param string $method
	 * @param array $arguments
	 * @return mixed
	 *
	 */ 
	public function __call($method, $arguments) {
		$result = $this->runHooks($method, $arguments); 
		if(!$result['methodExists'] && !$result['numHooksRun']) 
			throw new WireException("Method " . $this->className() . "::$method does not exist or is not callable in this context"); 
		return $result['return'];
	}

	/**
	 * Provides the implementation for calling hooks in ProcessWire
	 *
	 * Unlike __call, this method won't trigger an Exception if the hook and method don't exist. 
	 * Instead it returns a result array containing information about the call. 
	 *
	 * @param string $method Method or property to run hooks for.
	 * @param array $arguments Arguments passed to the method and hook. 
	 * @param string $type May be either 'method' or 'property', depending on the type of call. Default is 'method'.
	 * @return array Returns an array with the following information: 
	 * 	[return] => The value returned from the hook or NULL if no value returned or hook didn't exist. 
	 *	[numHooksRun] => The number of hooks that were actually run. 
	 *	[methodExists] => Did the hook method exist as a real method in the class? (i.e. with 3 underscores ___method).
	 *
	 */
	public function runHooks($method, $arguments, $type = 'method') {

		$result = array(
			'return' => null, 
			'numHooksRun' => 0, 
			'methodExists' => false,
			);

		$realMethod = "___$method";
		if($type == 'method') $result['methodExists'] = method_exists($this, $realMethod);
		if(!$result['methodExists'] && !self::isHooked($method . ($type == 'method' ? '()' : ''))) return $result; // exit quickly when we can

		$hooks = $this->getHooks();

		foreach(array('before', 'after') as $when) {

			if($type === 'method' && $when === 'after') {
				if($result['methodExists']) $result['return'] = call_user_func_array(array($this, $realMethod), $arguments); 
					else $result['return'] = null;
			}

			foreach($hooks as $priority => $hook) {

				if($hook['method'] !== $method) continue; 
				if(!$hook['options'][$when]) continue; 

				$event = new HookEvent(); 
				$event->object = $this;
				$event->method = $method;
				$event->arguments = $arguments;  
				$event->when = $when; 
				$event->return = $result['return']; 
				$event->id = $hook['id']; 
				$event->options = $hook['options']; 

				$toObject = $hook['toObject'];		
				$toMethod = $hook['toMethod']; 

				if(is_null($toObject)) $toMethod($event); 
					else $toObject->$toMethod($event); 

				$result['numHooksRun']++;

				if($when == 'after') $result['return'] = $event->return; 
			}	

		}

		return $result;
	}

	/**
	 * Return all hooks associated with this class instance or method (if specified)
	 *
	 * @param string $method Optional method that hooks will be limited to
	 * @return array
	 *
	 */
	public function getHooks($method = '') {

		$hooks = $this->localHooks; 

		foreach(self::$staticHooks as $className => $staticHooks) {
			// join in any related static hooks to the instance hooks
			if($this instanceof $className) {
				// TODO determine if the local vs static priority level may be damaged by the array_merge
				$hooks = array_merge($hooks, $staticHooks); 
			}
		}

		if($method) {
			$methodHooks = array();
			foreach($hooks as $priority => $hook) {
				if($hook['method'] == $method) $methodHooks[$priority] = $hook;
			}
			$hooks = $methodHooks;
		}

		return $hooks;
	}

	/**
	 * Returns true if the method/property hooked, false if it isn't.
	 *
	 * This is for optimization use. It does not distinguish about class or instance. 
	 *
	 * If checking for a hooked method, it should be in the form "method()". 
	 * If checking for a hooked property, it should be in the form "property". 
	 *
	 */
	static protected function isHooked($method) {
		return in_array($method, self::$hookMethodCache);
	}

	/**
	 * Hook a function/method to a hookable method call in this object
	 *
	 * Hookable method calls are methods preceded by three underscores. 
	 * You may also specify a method that doesn't exist already in the class
	 * The hook method that you define may be part of a class or a globally scoped function. 
	 *
	 * @param string $method Method name to hook into, NOT including the three preceding underscores. May also be Class::Method for same result as using the fromClass option.
	 * @param object|null $toObject Object to call $toMethod from, or null if $toMethod is a function outside of an object
	 * @param string $toMethod Method from $toObject, or function name to call on a hook event
	 * @param array $options See self::$defaultHookOptions at the beginning of this class
	 * @return string A special Hook ID that should be retained if you need to remove the hook later
	 *
	 */
	public function addHook($method, $toObject, $toMethod, $options = array()) {

		if(substr($method, 0, 3) == '___') throw new WireException("You must specify hookable methods without the 3 preceding underscores"); 
		if(method_exists($this, $method)) throw new WireException("Method " . $this->className() . "::$method is not hookable"); 

		$options = array_merge(self::$defaultHookOptions, $options);
		if(strpos($method, '::')) list($options['fromClass'], $method) = explode('::', $method); 

		if($options['allInstances'] || $options['fromClass']) {
			// hook all instances of this class
			$hookClass = $options['fromClass'] ? $options['fromClass'] : $this->className();
			if(!isset(self::$staticHooks[$hookClass])) self::$staticHooks[$hookClass] = array();
			$hooks =& self::$staticHooks[$hookClass]; 

		} else {
			// hook only this instance
			$hookClass = '';
			$hooks =& $this->localHooks;
		}

		$priority = (int) $options['priority']; 
		while(isset($hooks[$priority])) $priority++;
		$id = "$hookClass:$priority";

		$hooks[$priority] = array(
			'id' => $id, 
			'method' => $method, 
			'toObject' => $toObject, 
			'toMethod' => $toMethod, 
			'options' => $options, 
			); 

		self::$hookMethodCache[] = $options['type'] == 'method' ? "$method()" : "$method"; 

		ksort($hooks); // sort by priority
		return $id;
	}

	/**
	 * Shortcut to the addHook() method which adds a hook to be executed before the hooked method. 
	 *
	 * This is the same as calling addHook with the 'before' option set the $options array. 
	 *
	 * @param string $method Method name to hook into, NOT including the three preceding underscores
	 * @param object|null $toObject Object to call $toMethod from, or null if $toMethod is a function outside of an object
	 * @param string $toMethod Method from $toObject, or function name to call on a hook event
	 * @param array $options See self::$defaultHookOptions at the beginning of this class
	 * @return string A special Hook ID that should be retained if you need to remove the hook later
	 *
	 */
	public function addHookBefore($method, $toObject, $toMethod, $options = array()) {
		$options['before'] = true; 
		if(!isset($options['after'])) $options['after'] = false; 
		return $this->addHook($method, $toObject, $toMethod, $options); 
	}

	/**
	 * Shortcut to the addHook() method which adds a hook to be executed after the hooked method. 
	 *
	 * This is the same as calling addHook with the 'after' option set the $options array. 
	 *
	 * @param string $method Method name to hook into, NOT including the three preceding underscores
	 * @param object|null $toObject Object to call $toMethod from, or null if $toMethod is a function outside of an object
	 * @param string $toMethod Method from $toObject, or function name to call on a hook event
	 * @param array $options See self::$defaultHookOptions at the beginning of this class
	 * @return string A special Hook ID that should be retained if you need to remove the hook later
	 *
	 */
	public function addHookAfter($method, $toObject, $toMethod, $options = array()) {
		$options['after'] = true; 
		if(!isset($options['before'])) $options['before'] = false; 
		return $this->addHook($method, $toObject, $toMethod, $options); 
	}

	/**
	 * Shortcut to the addHook() method which adds a hook to be executed as an object property. 
	 *
	 * i.e. $obj->property; in addition to $obj->property(); 
	 *
	 * This is the same as calling addHook with the 'type' option set to 'property' in the $options array. 
	 * Note that descending classes that override __get must call getHook($property) and/or runHook($property).
	 *
	 * @param string $method Method name to hook into, NOT including the three preceding underscores
	 * @param object|null $toObject Object to call $toMethod from, or null if $toMethod is a function outside of an object
	 * @param string $toMethod Method from $toObject, or function name to call on a hook event
	 * @param array $options See self::$defaultHookOptions at the beginning of this class
	 * @return string A special Hook ID that should be retained if you need to remove the hook later
	 *
	 */
	public function addHookProperty($property, $toObject, $toMethod, $options = array()) {
		$options['type'] = 'property'; 
		return $this->addHook($property, $toObject, $toMethod, $options); 
	}

	/**
	 * Given a Hook ID provided by addHook() this removes the hook
	 *
	 * @param string $hookId
	 * @return this
	 *
	 */
	public function removeHook($hookId) {
		list($hookClass, $priority) = explode(':', $hookId); 
		if(!$hookClass) unset($this->localHooks[$priority]); 
			else unset(self::$staticHooks[$hookClass][$priority]); 
		return $this;
	}


	/**
	 * Has the given property changed? 
	 *
	 * Applicable only for properties you are tracking while $trackChanges is true. 
	 *
	 * @param string $what Name of property, or if left blank, check if any properties have changed. 
	 * @return bool
	 *
	 */
	public function isChanged($what = '') {
		if(!$what) return count($this->changes) > 0; 
		return in_array($what, $this->changes); 
	}

	/**
	 * Hookable method that is called whenever a property has changed while self::$trackChanges is true
	 *
	 * Enables hooks to monitor changes to the object. 
	 *
	 */
	public function ___changed($what) {
		// for hooks to listen to 
	}

	/**
	 * Track a change to a property in this object
	 *
	 * The change will only be recorded if self::$trackChanges is true. 
	 *
	 * @param string $what Name of property that changed
	 * @return this
	 * 
	 */
	public function trackChange($what) {
		if($this->trackChanges) {
			$this->changes[] = $what; 	
			$this->changed($what); 
		}
		return $this; 
	}

	/**
	 * Turn change tracking ON or OFF
	 *
	 * @param bool $trackChanges True to turn on, false to turn off. If not specified, true is assumed. 
	 * @return this
	 *
	 */
	public function setTrackChanges($trackChanges = true) {
		$this->trackChanges = $trackChanges; 
		return $this; 
	}

	/**
	 * Returns true if chagne tracking is on, or false if it's not. 
	 *
	 * @return bool
	 * 
	 */
	public function trackChanges() {
		return $this->trackChanges; 
	}

	/**
	 * Clears out any tracked changes and turns change tracking ON or OFF
	 *
	 * @param bool $trackChanges True to turn change tracking ON, or false to turn OFF. Default of true is assumed. 
	 * @return this
	 *
	 */
	public function resetTrackChanges($trackChanges = true) {
		$this->changes = array();
		return $this->setTrackChanges($trackChanges); 
	}

	/**
	 * Return an array of properties that have changed while change tracking was on. 
	 *
	 * @return array
	 *
	 */
	public function getChanges() {
		return $this->changes; 
	}

	/**
	 * Record an informational or 'success' message in the system-wide notices. 
	 *
	 * This method automatically identifies the message as coming from this class. 
	 *
	 * @param string $text
	 * @return this
	 *
	 */
	public function message($text) {
		$notice = new NoticeMessage($text); 
		$notice->class = $this->className();
		$this->fuel('notices')->add($notice); 
		return $this; 
	}

	/**
	 * Record an non-fatal error message in the system-wide notices. 
	 *
	 * This method automatically identifies the error as coming from this class. 
	 *
	 * Fatal errors should still throw a WireException (or class derived from it)
	 *
	 * @param string $text
	 * @return this
	 *
	 */
	public function error($text) {
		$notice = new NoticeError($text); 
		$notice->class = $this->className();
		$this->fuel('notices')->add($notice); 
		return $this; 
	}

}


