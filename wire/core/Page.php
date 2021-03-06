<?php

/**
 * ProcessWire Page
 *
 * Page is the class used by all instantiated pages and it provides functionality for:
 *
 * 1. Providing get/set access to the Page's properties
 * 2. Accessing the related hierarchy of pages (i.e. parents, children, sibling pages)
 * 
 * ProcessWire 2.x 
 * Copyright (C) 2010 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */

class Page extends WireData implements HasRoles {

	/*
	 * The following constant flags are specific to a Page's 'status' field. A page can have 1 or more flags using bitwise logic. 
	 * Status levels 1024 and above are excluded from search by the core. Status levels 16384 and above are runtime only and not 
	 * stored in the DB unless for logging or page history.
	 *
	 * If the under 1024 status flags are expanded in the future, it must be ensured that the combined value of the searchable flags 
	 * never exceeds 1024, otherwise issues in Pages::find() will need to be considered. 
	 *
	 * The status levels 16384 and above can safely be changed as needed as they are runtime only. 
	 *
	 */
	const statusOn = 1; 			// base status for all pages
	const statusLocked = 4; 		// page locked for changes. Not enforced by the core, but checked by Process modules. 
	const statusHidden = 1024;		// page is excluded selector methods like $pages->find() and $page->children() unless status is specified, like "status&1"
	const statusUnpublished = 2048; 	// page is not published and is not renderable. 
	const statusTrash = 8192; 		// page is in the trash
	const statusDeleted = 16384; 		// page is deleted (runtime only)
	const statusCorrupted = 131072; 	// page was corrupted at runtime and is NOT saveable: see setFieldValue() and $outputFormatting. (runtime)
	const statusMax = 9999999;		// number to use for max status comparisons, runtime only

	/**
	 * The Template this page is using (object)
	 *
	 */
	protected $template; 

	/**
	 * The previous template used by the page, if it was changed during runtime. 	
	 *
	 * Allows Pages::save() to delete data that's no longer used. 
	 *
	 */
	private $templatePrevious; 

	/**
	 * Parent Page - Instance of Page
	 *
	 */
	protected $parent = null;

	/**
	 * The previous parent used by the page, if it was changed during runtime. 	
	 *
	 * Allows Pages::save() to identify when the parent has changed
	 *
	 */
	private $parentPrevious; 

	/**
	 * Reference to the Page's template file, used for output. Instantiated only when asked for. 
	 *
	 */
	private $output; 

	/**
	 * Instance of PageFilesManager, which manages and migrates file versions for this page
	 *
	 * Only instantiated upon request, so access only from filesManager() method in Page class. 
	 * Outside API can use $page->filesManager.
	 *
	 */
	private $filesManager = null;

	/**
	 * RolesArray containing Role instances that are assigned to this page. 
	 *
	 * Dynamically set as needed, refer only to roles() method rather than this directly. 
	 *
	 */
	private $rolesArray = null;

	/**
	 * Instance of User that originally created this Page
	 *
	 */
	protected $createdUser = null;

	/**
	 * Instance of User that originally created this Page
	 *
	 */
	protected $modifiedUser = null;

	/**
	 * Field data that queues while the page is loading. 
	 *
	 * Once setIsLoaded(true) is called, this data is processed and instantiated into the Page and the fieldDataQueue is emptied (and no longer relevant)	
	 *
	 */
	protected $fieldDataQueue = array();

	/**
	 * Is this a new page (not yet existing in the database)?
	 *
	 */
	protected $isNew = true; 

	/**
	 * Is this Page finished loading from the DB (i.e. Pages::getById)?
	 *
	 * When false, it is assumed that any values set need to be woken up. 
	 * When false, it also assumes that built-in properties (like name) don't need to be sanitized. 
	 *
	 * Note: must be kept in the 'true' state. Pages::getById sets it to false before populating data and then back to true when done.
	 *
	 */
	protected $isLoaded = true; 

	/**
	 * Is this page allowing it's output to be formatted?
	 *
	 * If so, the page may not be saveable because calls to $page->get(field) are returning versions of 
	 * variables that may have been formatted at runtime for output. An exception will be thrown if you
	 * attempt to set the value of a formatted field when $outputFormatting is on. 
	 *
	 * Output formatting should be turned off for pages that you are manipulating and saving. 
	 * Whereas it should be turned on for pages that are being used for output on a public site. 
	 * Having it on means that Textformatters and any other output formatters will be executed
	 * on any values returned by this page. Likewise, any values you set to the page while outputFormatting
	 * is set to true are considered potentially corrupt. 
	 *
	 */
	protected $outputFormatting = false; 

	/**
	 * A unique instance ID assigned to the page at the time it's loaded (for debugging purposes only)
	 *
	 */
	protected $instanceID = 0; 

	/**
	 * IDs for all the instances of pages, used for debugging and testing.
	 *
	 * Indexed by $instanceID => $pageID
	 *
	 */
	static public $instanceIDs = array();

	/**
	 * The current page number, starting from 1
	 *
	 */
	protected $pageNum = 1; 

	/**
	 * Reference to main config, optimization so that get() method doesn't get called
	 *
	 */
	protected $config = null; 

	/**
	 * Page-specific settings which are either saved in pages table, or generated at runtime.
	 *
	 */
	protected $settings = array(
		'id' => 0, 
		'name' => '', 
		'status' => 1, 
		'numChildren' => 0, 
		'sort' => 0, 
		'sortfield' => 'sort', 
		); 

	/**
	 * Create a new page in memory. 
	 *
	 * @param Template $tpl Template object this page should use. 
	 *
	 */
	public function __construct(Template $tpl = null) {

		if(!is_null($tpl)) $this->template = $tpl;
		$this->config = $this->fuel('config'); 
		$this->templatePrevious = null;
		$this->parentPrevious = null;
	}

	/**
	 * Destruct this page instance
	 *
	 */
	public function __destruct() {
		if($this->instanceID) {
			// remove from the record of instanceID, so that we have record of page's that HAVEN'T been destructed. 
			unset(self::$instanceIDs[$this->instanceID]); 
		}
	}

	/**
	 * Clone this page instance
	 *
	 */
	public function __clone() {
		$track = $this->trackChanges();
		$this->setTrackChanges(false); 
		if($this->rolesArray) $this->rolesArray = clone $this->rolesArray; 
		if($this->filesManager) {
			$this->filesManager = clone $this->filesManager; 
			$this->filesManager->setPage($this);
		}
		foreach($this->template->fieldgroup as $field) {
			$name = $field->name; 
			if(!$field->type->isAutoload() && !isset($this->data[$name])) continue; // important for draft loading
			if(is_object($this->$name)) $this->set($name, clone $this->$name); 
		}
		$this->instanceID .= ".clone";
		if($track) $this->setTrackChanges(true); 
	}

	/**
	 * Set the value of a page property
	 *
	 * @param string $key Property to set
	 * @param mixed $value
	 * @return Page Reference to this Page
	 * @see __set
	 *
	 */
	public function set($key, $value) {
		switch($key) {
			case 'id':
			case 'sort': 
			case 'status':
			case 'numChildren': 
				if($this->settings[$key] !== $value) $this->trackChange($key); 
				$this->settings[$key] = (int) $value; 
				break;
			case 'name':
				if($this->isLoaded) {
					$beautify = empty($this->settings[$key]); 
					$value = $this->fuel('sanitizer')->pageName($value, $beautify); 
					if($this->settings[$key] !== $value) $this->trackChange($key); 
				}
				$this->settings[$key] = $value; 
				break;
			case 'parent': 
			case 'parent_id':
				if($key == 'parent_id' && $value) $value = $this->fuel('pages')->get((int)$value); 
				if($value) $this->setParent($value);
				break;
			case 'template': 
			case 'templates_id':
				if($key == 'templates_id' && $this->template && $this->template->id == $value) break;
				if($key == 'templates_id') $value = $this->fuel('templates')->get((int)$value); 
				$this->setTemplate($value); 
				break;
			case 'created': 
			case 'modified':
				if(!ctype_digit("$value")) $value = strtotime($value); 
				$this->settings[$key] = (int) $value; 
				break;
			case 'created_users_id':
			case 'modified_users_id': 
			case 'createdUser':
			case 'modifiedUser':
				$this->setUser($value, strpos($key, 'created') === 0 ? 'created' : 'modified'); 
				break;
			case 'sortfield':
				$value = $this->fuel('pages')->sortfields()->decode($value); 
				if($this->settings[$key] != $value) $this->trackChange($key); 
				$this->settings[$key] = $value; 
				break;
			case 'isLoaded': 
				$this->setIsLoaded($value); 
				break;
			case 'pageNum':
				$this->pageNum = ((int) $value) > 1 ? (int) $value : 1; 
				break;
			case 'instanceID': 
				$this->instanceID = $value; 
				self::$instanceIDs[$value] = $this->settings['id']; 
				break;
			default:
				$this->setFieldValue($key, $value, $this->isLoaded); 

		}
		return $this; 
	}


	/**
	 * Set the value of a field that is defined in the page's Fieldgroup
	 *
	 * This may not be called when outputFormatting is on. 
	 *
	 * This is for internal use. API should generally use the set() method, but this is kept public for the minority of instances where it's useful.
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param bool $load Should the existing value be loaded for change comparisons? (applicable only to non-autoload fields)
	 *
	 */
	public function setFieldValue($key, $value, $load = true) {

		if(!$this->template) throw new WireException("You must assign a template to the page before setting custom field values."); 

		// if the page is not yet loaded and a '__' field was set, then we queue it so that the loaded() method can 
		// instantiate all those fields knowing that all parts of them are present for wakeup. 
		if(!$this->isLoaded && strpos($key, '__')) {
			list($key, $subKey) = explode('__', $key); 
			if(!isset($this->fieldDataQueue[$key])) $this->fieldDataQueue[$key] = array();
			$this->fieldDataQueue[$key][$subKey] = $value; 
			return;
		}

		if(!$field = $this->template->fieldgroup->getField($key)) {
			// not a known/saveable field, let them use it for runtime storage
			return parent::set($key, $value); 
		}

		// if a null value is set, then ensure the proper blank type is set to the field
		if(is_null($value)) {
			return parent::set($key, $field->type->getBlankValue($this, $field)); 
		}

		// if the page is currently loading from the database, we assume that any set values are 'raw' and need to be woken up
		if(!$this->isLoaded) {

			// send the value to the Fieldtype to be woken up for storage in the page
			$value = $field->type->wakeupValue($this, $field, $value); 

			// page is currently loading, so we don't need to continue any further
			return parent::set($key, $value); 
		}

		// check if the field hasn't been already loaded
		if(is_null(parent::get($key))) {
			// this field is not currently loaded. if the $load param is true, then ...
			// retrieve old value first in case it's not autojoined so that change comparisons and save's work 
			if($load && $this->isLoaded) $this->get($key); 

		} else if($this->outputFormatting && $field->type->formatValue($this, $field, $value) != $value) {
			// The field has been loaded or dereferenced from the API, and this field changes when formatters are applied to it. 
			// There is a good chance they are trying to set a formatted value, and we don't allow this situation because the 
			// possibility of data corruption is high. We set the Page::statusCorrupted status so that Pages::save() can abort.
			$this->set('status', $this->status | self::statusCorrupted); 
		}

		// ensure that the value is in a safe format and set it 
		$value = $field->type->sanitizeValue($this, $field, $value); 

		parent::set($key, $value); 
	}

	/**
	 * Get the value of a requested Page property
	 *
	 * @param string $key
	 * @return mixed
	 * @see __get
	 *
	 */
	public function get($key) {
		$value = null;
		switch($key) {
			case 'parent_id':
			case 'parentID': 
				$value = $this->parent ? $this->parent->id : 0; 
				break;
			case 'child':
				$value = $this->child(); 
				break;
			case 'children':
			case 'subpages': // PW1 
				$value = $this->children();
				break;
			case 'parent':
			case 'parents':
			case 'rootParent':
			case 'siblings':
			case 'roles':
			case 'url':
			case 'path':
			case 'outputFormatting': 
			case 'isTrash': 
				$value = $this->{$key}(); 
				break;
			case 'httpUrl': 
			case 'httpURL': 
				$value = $this->httpUrl();
				break;
			case 'fieldgroup': 
			case 'fields':
				$value = $this->template->fieldgroup; 
				break; 
			case 'template':
			case 'templatePrevious':
			case 'parentPrevious':
			case 'isLoaded':
			case 'isNew':
			case 'pageNum':
				$value = $this->$key; 
				break;
			case 'out':
			case 'output':
				$value = $this->getTemplateFile();
				break;
			case 'filesManager':
				$value = $this->filesManager();
				break;
			case 'name':
				// if a page has no name, then it's name is it's ID
				$value = strlen($this->settings['name']) ? $this->settings['name'] : $this->id; 
				break;
			case 'modified_users_id': 
			case 'modifiedUsersID':
			case 'modifiedUserID':
				$value = $this->modifiedUser ? $this->modifiedUser->id : null; 
				break;
			case 'created_users_id':
			case 'createdUsersID':
			case 'createdUserID': 
				$value = $this->createdUser ? $this->createdUser->id : null; 
				break;
			case 'modifiedUser':
			case 'createdUser':
				if(!$value = $this->$key) $value = new NullUser();
				break;
			case 'urlSegment':
				return $this->input->urlSegment1; // deprecated, but kept for backwards compatibility
				break;
			default:
				if($key && isset($this->settings[(string)$key])) return $this->settings[$key]; 

				if(($value = $this->getFieldFirstValue($key)) === null) {
					if(($value = $this->getFieldValue($key)) === null) {
						// if there is a selector, we'll assume they are using the get() method to get a child
						if(Selectors::stringHasOperator($key)) $value = $this->child($key); 
					}
				}
		}

		return $value; 
	}

	/**
	 * Given a Multi Key, determine if there are multiple keys requested and return the first non-empty value
	 *
	 * A Multi Key is a string with multiple field names split by pipes, i.e. headline|title
	 *
	 * Example: browser_title|headline|title - Return the value of the first field that is non-empty
	 *
	 * @param string $key
	 * @return null|mixed Returns null if no values match, or if there aren't multiple keys split by "|" chars
	 *
	 */
	protected function getFieldFirstValue($multiKey) {

		// looking multiple keys split by "|" chars, and not an '=' selector
		if(strpos($multiKey, '|') === false || strpos($multiKey, '=') !== false) return null;

		$value = null;
		$keys = explode('|', $multiKey); 

		foreach($keys as $key) {
			$value = $this->getFieldValue($key);
			if(is_string($value)) $value = trim($value); 
			if($value) break;
		}

		return $value;
	}

	/**
	 * Get the value for a non-native page field, and call upon Fieldtype to join it if not autojoined
	 *
	 * @param string $key
	 * @return null|mixed
	 *
	 */
	protected function getFieldValue($key) {
		if(!$this->template) return null;
		$field = $this->template->fieldgroup->getField($key); 
		$value = parent::get($key); 
		if(!$field) return $value;  // likely a runtime field, not part of our data

		// if the value is already loaded, return it 
		if(!is_null($value)) return $this->outputFormatting ? $field->type->formatValue($this, $field, $value) : $value; 
		$track = $this->trackChanges();
		$this->setTrackChanges(false); 
		$value = $field->type->loadPageField($this, $field); 
		if(is_null($value)) $value = $field->type->getDefaultValue($this, $field); 
			else $value = $field->type->wakeupValue($this, $field, $value); 

		// if outputFormatting is being used, turn it off because it's not necessary here and may throw an exception
		$outputFormatting = $this->outputFormatting; 
		if($outputFormatting) $this->setOutputFormatting(false); 
		$this->setFieldValue($key, $value, false); 
		if($outputFormatting) $this->setOutputFormatting(true); 
		
		$value = parent::get($key); 	
		if(is_object($value) && $value instanceof Wire) $value->setTrackChanges(true);
		if($track) $this->setTrackChanges(true); 
		return $this->outputFormatting ? $field->type->formatValue($this, $field, $value) : $value; 
	}

	/**
	 * Get the raw/unformatted value of a field, regardless of what $this->outputFormatting is set at
	 *
	 */
	public function getUnformatted($key) {
		$outputFormatting = $this->outputFormatting; 
		if($outputFormatting) $this->setOutputFormatting(false); 
		$value = $this->get($key); 
		if($outputFormatting) $this->setOutputFormatting(true); 
		return $value; 
	}


	/**
	 * @see get
	 *
	 */
	public function __get($key) {
		return $this->get($key); 
	}

	/**
	 * @see set
	 *
	 */
	public function __set($key, $value) {
		$this->set($key, $value); 
	}

	/**
	 * Set this Page's Template object
	 *
	 */
	protected function setTemplate($tpl) {
		if(!is_object($tpl)) $tpl = $this->fuel('templates')->get($tpl); 
		if(!$tpl instanceof Template) throw new WireException("Invalid value sent to Page::setTemplate"); 
		if($this->template && $this->template->id != $tpl->id) {
			if(is_null($this->templatePrevious)) $this->templatePrevious = $this->template; 
			$this->trackChange('template'); 
		}
		$this->template = $tpl; 
		return $this;
	}


	/**
	 * Set this page's parent Page
	 *
	 */
	protected function setParent(Page $parent) {
		if($this->parent && $this->parent->id == $parent->id) return $this; 
		$this->trackChange('parent');
		if($this->parent && $this->parent->id) $this->parentPrevious = $this->parent; 
		$this->parent = $parent; 
		return $this; 
	}


	/**
	 * Set either the createdUser or the modifiedUser 
	 *
	 * @param User|int|string User object or integer/string representation of User
	 * @param string $userType Must be either 'created' or 'modified' 
	 * @return this
	 *
	 */
	protected function setUser($user, $userType) {

		if(!$user instanceof User) $user = $this->fuel('users')->get($user); 

		// if they are setting an invalid user or unknown user, then the Page defaults to the super user
		if(!$user) $user = $this->fuel('users')->get(User::superUserID); 

		if($userType == 'created') $field = 'createdUser';
			else if($userType == 'modified') $field = 'modifiedUser';
			else throw new WireException("Unknown user type in Page::setUser(user, type)"); 

		$existingUser = $this->$field; 
		if($existingUser && $existingUser->id != $user->id) $this->trackChange($field); 
		$this->$field = $user; 
		return $this; 	
	}

	/**
	 * Return this page's parent Page
	 *
	 */
	public function parent() {
		return $this->parent ? $this->parent : new NullPage(); 
	}

	/**
	 * Find Pages in the descendent hierarchy
	 *
	 * Same as Pages::find() except that the results are limited to descendents of this Page
	 *
	 * @param string $selector
	 *
	 */
	public function find($selector) {
		if(!$this->numChildren) return new PageArray();
		$selector = "has_parent={$this->id}, $selector"; 
		return $this->fuel('pages')->find(trim($selector, ", ")); 
	}

	/**
	 * Return this page's children pages, optionally filtered by a selector
	 *
	 * @param string $selector Selector to use, or blank to return all children
	 * @return PageArray
	 *
	 */
	public function children($selector = '') {
		if(!$this->numChildren) return new PageArray();
		if($selector) $selector .= ", ";
		$selector = "parent_id={$this->id}, $selector"; 
		if(strpos($selector, 'sort=') === false) $selector .= "sort={$this->sortfield}"; 
		return $this->fuel('pages')->find(trim($selector, ", ")); 
	}

	/**
	 * Return the page's first single child that matches the given selector. 
	 *
	 * Same as children() but returns a Page object or NULL rather than a PageArray.
	 *
	 * @param string $selector Selector to use, or blank to return the first child. 
	 * @return Page|null
	 *
	 */
	public function child($selector = '') {
		$selector .= ($selector ? ', ' : '') . "limit=1";
		$children = $this->children($selector); 
		return count($children) ? $children->first() : new NullPage();
	}

	/**
	 * Return this page's NON-HIDDEN children pages, optionally filtered by a selector
	 *
	 * This is suitable to call for generating navigation.
	 *
	 * @deprecated This functionality is now handled by default with the regular children() method. 
	 *
	 */
	public function navChildren($selector = '') {
		return $this->children($selector); 
		/*
		$selector .= ($selector ? ", " : '') . "!status&" . Page::statusHidden;
		$children = $this->children($selector); 
		foreach($children as $child) {
			if(!$child->listable()) $children->remove($child); 
		}
		return $children; 
		*/
	}

	/**
	 * Return this page's parent pages. 
	 *
	 */
	public function parents() {
		$parents = new PageArray();
		$parent = $this->parent();
		while($parent && $parent->id) {
			$parents->prepend($parent); 	
			$parent = $parent->parent();
		}
		return $parents; 
	}

	/**
	 * Get the lowest-level, non-homepage parent of this page
	 *
	 * rootParents typically comprise the first level of navigation on a site. 
	 *
	 * @return Page 
	 *
	 */
	public function rootParent() {
		if(!$this->parent || $this->parent->id === 1) return $this; 
		$parents = $this->parents();
		$parents->shift(); // shift off homepage
		return $parents->first();
	}

	/**
	 * Return this Page's sibling pages, optionally filtered by a selector. 
	 *
	 */
	public function siblings($selector = '') {
		if($selector) $selector .= ", ";
		$selector = "parent_id={$this->parent_id}, $selector";
		if(strpos($selector, 'sort=') === false) $selector .= "sort=" . ($this->parent ? $this->parent->sortfield : 'sort'); 
		return $this->fuel('pages')->find(trim($selector, ", ")); 
	}

	/**
	 * Save this page to the database. 
	 *
	 * To hook into this (___save), use Pages::save instead. 
	 * To hook into a field-only save, use Fieldtype::savePageField
	 *
	 * @param Field|string $field Optional field to save (name of field or Field object)
	 *
	 */
	public function save($field = null) {

		if($field) {
			if(is_string($field)) $field = $this->fuel('fields')->get($field); 
			if(!$field instanceof Field) throw new WireException("Unable to save unknown field from Page '$this'"); 
			if(!$this->fieldgroup->has($field)) throw new WireException("Unable to save field '$field' to page '$this' because the field is not part of it"); 
			$value = $this->get($field->name); 
			if($value instanceof Pagefiles || $value instanceof Pagefile) $this->filesManager()->save();
			$this->trackChange($field->name); 	
			return $field->type->savePageField($this, $field); 
		} else {
			return $this->fuel('pages')->save($this);
		}
	}

	/**
	 * Delete this page from the Database
	 *
	 */
	public function delete() {
		return $this->fuel('pages')->delete($this); 
	}

	/**
	 * Allow iteration of the page's properties with foreach(), fulfilling IteratorAggregate interface.
	 *
	 */
	public function getIterator() {
		$a = $this->settings; 
		foreach($this->template->fieldgroup as $field) {
			$a[$field->name] = $this->get($field->name); 
		}
		return new ArrayObject($a); 	
	}


	/**
	 * Has the Page (or optionally one of it's fields) changed since it was loaded?
	 *
	 * Assumes that Pages has turned on this Page's change tracking with a call to setTrackChanges(). 
	 * Pages that are new (i.e. don't yet exist in the DB) always return true. 
	 * 
	 * @param string $what If specified, only checks the given property for changes rather than the whole page. 
	 * @return bool 
	 *
	 */
	public function isChanged($what = '') {
		if($this->isNew()) return true; 
		if(parent::isChanged($what)) return true; 
		$changed = false;
		if($what) {
			$value = $this->get($what); 
			if(is_object($value) && $value instanceof Wire) 
				$changed = $value->isChanged(); 
		} else {
			foreach($this->data as $key => $value) {
				if(is_object($value) && $value instanceof Wire)
					$changed = $value->isChanged();
				if($changed) break;
			}
		}

		return $changed; 	
	}


	/**
	 * Returns the Page's ID in a string
	 *
	 */
	public function __toString() {
		return "{$this->id}"; 
	}

	/**
	 * Returns the Page's path from the site root. 
	 *
	 */
	public function path() {
		if($this->id === 1) return '/';
		$path = '';
		$parents = $this->parents();
		foreach($parents as $parent) if($parent->id > 1) $path .= "/{$parent->name}";
		return $path . '/' . $this->name . '/'; 
	}

	/**
	 * Like path() but comes from server document root (which may or may not be different)
	 *
	 * Does not include urlSegment, if applicable. 
	 * Does not include protocol and hostname -- use httpUrl() for that.
	 *
	 * @see path
	 *
	 */
	public function url() {
		return rtrim($this->fuel('config')->urls->root, "/") . $this->path; 
	}

	/**
	 * Like URL, but includes the protocol and hostname
	 *
	 */
	public function httpUrl() {

		switch($this->template->https) {
			case -1: $protocol = 'http'; break;
			case 1: $protocol = 'https'; break;
			default: $protocol = $this->config->https ? 'https' : 'http'; 
		}

		return "$protocol://{$this->config->httpHost}" . $this->url();
	}

	/**
	 * Get the output TemplateFile object for rendering this page
	 *
	 * You can retrieve the results of this by calling $page->out or $page->output
	 *
	 * @return TemplateFile
	 *
	 */
	protected function getTemplateFile() {
		if($this->output) return $this->output; 
		$this->output = new TemplateFile($this->template->filename); 
		$fuel = self::getAllFuel();
		$this->output->set('wire', $fuel); 
		foreach($fuel as $key => $value) $this->output->set($key, $value); 
		$this->output->set('page', $this); 
		return $this->output; 
	}


	/**
	 * Return the active Roles on this page
	 *
	 * @return Roles
	 *
	 */
	public function roles() {
		if(is_null($this->rolesArray)) $this->rolesArray = $this->fuel('pagesRoles')->findRolesByPage($this); 
		return $this->rolesArray; 
	}

	/**
	 * Add/activate a Role on this page
	 *
	 * Don't forget to call page->save();
	 *
	 * @param Role $role
	 * @return this
	 *
	 */
	public function addRole($role) {
		if(is_string($role) || is_int($role)) $role = $this->fuel('roles')->get($role); 
		if(!$role) throw new WireException("Unknown or null role added to $this"); 
		$this->fuel('pagesRoles')->addRoleToPage($role, $this); 
		$this->roles()->add($role); 
		return $this; 
	}

	/**
	 * Does this Page specifically ADD the given role?
	 *
	 */
	public function addsRole($role) {
		$roles = $this->fuel('pagesRoles')->findRolesAddedByPage($this); 
		return $roles->has($role); 
	}

	/**
	 * Does this page contain the given role?
	 *
	 */
	public function hasRole($role) {
		return $this->roles()->has($role); 
	}

	/**
	 * Remove a Role from this page
	 *
	 * Don't forget to call page->save();
	 *
	 * @param Role $role
	 * @return this
	 *
	 */
	public function removeRole($role) {
		if(is_string($role) || is_int($role)) $role = $this->fuel('roles')->get($role); 
		if(!$role) throw new WireException("Page::removeRole was asked to remove a non-existant role"); 
		if($role->id == Role::superRoleID) throw new WireException("The super role may not be removed from a page"); 
		$this->fuel('pagesRoles')->removeRoleFromPage($role, $this); 
		$this->roles()->remove($role); 
		return $this; 
	}

	/**
	 * Does this page specifically Remove the given role?
	 *
	 */
	public function removesRole($role) {
		$roles = $this->fuel('pagesRoles')->findRolesRemovedByPage($this); 
		return $roles->has($role); 
	}

	/**
	 * Return a Inputfield object that contains all the custom Inputfield objects required to edit this page
	 *
	 */
	public function getInputfields() {
		return $this->template ? $this->template->fieldgroup->getPageInputfields($this) : null;
	}


	/** 
	 * Does this page have the specified status number or template name? 
 	 *
 	 * See status flag constants at top of Page class
	 *
	 * @param int|string $status Status number or Template name
	 * @return bool
	 *
	 */
	public function is($status) {
		if(is_int($status)) {
			return $this->status & $status; 
		} else if(is_string($status)) {
			if($this->template->name == $status) return true; 
		}
		return false;
	}


	/**
	 * Add the specified status flag to this page's status
	 *
	 * @param int $statusFlag
	 * @return this
	 *
	 */
	public function addStatus($statusFlag) {
		$statusFlag = (int) $statusFlag; 
		$this->status = $this->status | $statusFlag; 
		return $this;
	}

	/** 
	 * Remove the specified status flag from this page's status
	 *
	 * @param int $statusFlag
	 * @return this
	 *
	 */
	public function removeStatus($statusFlag) {
		$statusFlag = (int) $statusFlag; 
		$this->status = $this->status & ~$statusFlag; 
		return $this;
	}

	/**
	 * Does this page have a 'hidden' status?
	 *
	 * @return bool
	 *
	 */
	public function isHidden() {
		return $this->is(self::statusHidden); 
	}

	/**
	 * Is this Page new? (i.e. doesn't yet exist in DB)
	 *
	 */
	public function isNew() {
		return $this->isNew; 
	}

	/**
	 * Is the page fully loaded?
	 *
	 */
	public function isLoaded() {
		return $this->isLoaded; 
	}

	/**
	 * Is this Page in the trash?
	 *
	 * @return bool
	 *
 	 */ 
	public function isTrash() {
		if($this->is(self::statusTrash)) return true; 
		$trashPageID = $this->config->trashPageID; 
		if($this->id == $trashPageID) return true; 
		// this is so that isTrash() still returns the correct result, even if the page was just trashed and not yet saved
		foreach($this->parents() as $parent) if($parent->id == $trashPageID) return true; 
		return false;
	}

	/**
	 * Set the value for isNew, i.e. doesn't exist in the DB
	 *
	 * @param bool @isNew
	 * @return this
	 *
	 */
	public function setIsNew($isNew) {
		$this->isNew = $isNew ? true : false; 
		return $this; 
	}

	/**
	 * Set that the Page is fully loaded
	 *
	 * Pages::getById sets this once it has completed loading the page
	 * This method also triggers the loaded() method that hooks may listen to
	 *
	 * @param bool $isLoaded
	 *
	 */
	public function setIsLoaded($isLoaded) {
		if($isLoaded) $this->processFieldDataQueue();
		$this->isLoaded = $isLoaded ? true : false; 
		if($isLoaded) $this->loaded();
		return $this; 
	}

	/**
	 * Process and instantiate any data in the fieldDataQueue
	 *
	 * This happens after setIsLoaded(true) is called
	 *
	 */
	protected function processFieldDataQueue() {

		foreach($this->fieldDataQueue as $key => $value) {

			$field = $this->fieldgroup->get($key); 
			if(!$field) continue;

			// check for autojoin multi fields, which may have multiple values bundled into one string
			// as a result of an sql group_concat() function
			if($field->type instanceof FieldtypeMulti && ($field->flags & Field::flagAutojoin)) {
				foreach($value as $k => $v) {	
					if(is_string($v) && strpos($v, FieldtypeMulti::multiValueSeparator) !== false) {
						$value[$k] = explode(FieldtypeMulti::multiValueSeparator, $v); 	
					}
				}
			}

			// if all there is in the array is 'data', then we make that the value rather than keeping an array
			// this is so that Fieldtypes that only need to interact with a single value don't have to receive an array of data
			if(count($value) == 1 && array_key_exists('data', $value)) $value = $value['data']; 

			$this->setFieldValue($key, $value, false); 
		}
		$this->fieldDataQueue = array(); // empty it out, no longer needed
	}


	/**
	 * For hooks to listen to, triggered when page is loaded and ready
	 *
	 */
	public function ___loaded() { }


	/**
	 * Set if this page's output is allowed to be filtered by runtime formatters. 
	 *
	 * Pages used for output should have it on. 
	 * Pages you intend to manipulate and save should have it off. 
	 *
	 * @param bool @outputFormatting Optional, default true
	 * @return this
	 *
	 */
	public function setOutputFormatting($outputFormatting = true) {
		$this->outputFormatting = $outputFormatting ? true : false; 
		return $this; 
	}

	/**
	 * Return true if outputFormatting is on, false if not. 
	 *
	 * @return bool
	 *
	 */
	public function outputFormatting() {
		return $this->outputFormatting; 
	}

	/**
	 * Return instance of PagefileManager specific to this Page
	 *
	 * @return PageFilesManager
	 *
	 */
	public function filesManager() {
		if(is_null($this->filesManager)) $this->filesManager = new PagefilesManager($this); 
		return $this->filesManager; 
	}

	/**
	 * Prepare the page and it's fields for removal from runtime memory, called primarily by Pages::uncache()
	 *
	 */
	public function uncache() {
		foreach($this->fields as $field) {
			$value = parent::get($field->name);
			if($value != null && is_object($value)) {
				if(method_exists($value, 'uncache')) $value->uncache();
				parent::set($field->name, null); 
			}
		}
		if($this->filesManager) $this->filesManager->uncache(); 
		$this->filesManager = null;
	}

	/**
	 * Ensures that isset() and empty() work for this classes properties. 
	 *
	 */
	public function __isset($key) {
		if(isset($this->settings[$key])) return true; 
		return parent::__isset($key); 
	}

}

/**
 * Placeholder class for non-existant and non-saveable Page
 *
 */
class NullPage extends Page { 

	public function roles() { return new RolesArray(); }
	public function path() { return ''; }
	public function url() { return ''; }
	public function set($key, $value) { return $this; }
	public function parent() { return null; }
	public function parents() { return new PageArray(); } 
	public function __toString() { return ""; }
	public function isHidden() { return true; }
	public function filesManager() { return null; }
	public function rootParent() { return new NullPage(); }
	public function siblings($selector = '') { return new PageArray(); }
	public function children($selector = '') { return new PageArray(); }

}


