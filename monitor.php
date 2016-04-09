<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Search.monitor
 *
 * @copyright   Copyright (C) 2016 Constantin Romankiewicz.
 * @license     Apache License 2.0; see LICENSE
 */
defined('_JEXEC') or die;

/**
 * This plugin implements searching through issues and comments for the com_monitor extension.
 *
 * @author  Constantin Romankiewicz <constantin@zweiiconkram.de>
 * @since   1.0
 */
class PlgSearchMonitor extends JPlugin
{
	/**
	 * Database object
	 *
	 * @var    JDatabaseDriver
	 * @since  3.3
	 */
	protected $db;

	/**
	 * Defines which areas can be searched by this plugin.
	 *
	 * @return  array
	 */
	public function onContentSearchAreas()
	{
		static $areas = array(
			'issues' => 'PLG_SEARCH_MONITOR_ISSUES',
			'comments' => 'PLG_SEARCH_MONITOR_COMMENTS',
		);

		return $areas;
	}

	/**
	 * Event is fired when a user performs a search.
	 * This function checks if the search covers one of the areas specified in {@link onContentSearchAreas}
	 * and retrieves the according data from the database.
	 *
	 * @param   string  $text      Target search string.
	 * @param   string  $phrase    Matching option (possible values: exact|any|all).  Default is "any".
	 * @param   string  $ordering  Ordering option (possible values: newest|oldest|popular|alpha|category).  Default is "newest".
	 * @param   mixed   $areas     An array if the search it to be restricted to areas or null to search all areas.
	 *
	 * @return  array  Array consisting of objects with the attributes title, section, created, text, href and browsernav.
	 */
	public function onContentSearch($text, $phrase = '', $ordering = '', $areas = null)
	{
		// Check if the component is installed and enabled.
		if (!JComponentHelper::isEnabled('com_monitor'))
		{
			return array();
		}

		if (!is_array($areas))
		{
			return array();
		}

		if (!($relevantAreas = array_intersect($areas, array_keys($this->onContentSearchAreas()))))
		{
			return array();
		}

		$text = trim($text);

		if ($text == '')
		{
			return array();
		}

		$rows = array();

		if (in_array('issues', $relevantAreas))
		{
			$rows = array_merge($rows, $this->searchIssues($text, $phrase, $ordering));
		}

		if (in_array('comments', $relevantAreas))
		{
			$rows = array_merge($rows, $this->searchComments($text, $phrase, $ordering));
		}

		$compare = function ($a, $b) use ($ordering)
		{
			switch ($ordering)
			{
				case 'alpha':
					return strcasecmp($a->title, $b->title);
				case 'category':
					return strcasecmp($a->project, $b->project);
				case 'oldest':
					return ($a->created < $b->created) ? -1 : 1;

				// There is no such thing as popularity.
				case 'popular':
				case 'newest':
				default:
					return ($a->created > $b->created) ? -1 : 1;
			}
		};

		// Do we have both issues and comments?
		if (in_array('issues', $relevantAreas) && in_array('comments', $relevantAreas))
		{
			// -> reorder!
			usort($rows, $compare);
		}

		return $rows;
	}

	/**
	 * Search for issues in the database.
	 *
	 * @param   string  $text      Target search string.
	 * @param   string  $phrase    Matching option (possible values: exact|any|all).  Default is "any".
	 * @param   string  $ordering  Ordering option (possible values: newest|oldest|popular|alpha|category).  Default is "newest".
	 *
	 * @return  array  Array consisting of objects with the attributes title, section, created, text, href and browsernav.
	 */
	private function searchIssues($text, $phrase, $ordering)
	{
		$query = $this->db->getQuery(true);

		$query->select('i.id, i.title, p.name AS section, i.created, i.text')
			->from('#__monitor_issues AS i')
			->leftJoin('#__monitor_projects AS p ON i.project_id = p.id')
			->leftJoin('#__monitor_issue_classifications AS cl ON i.classification = cl.id');

		// ACL
		$query->where('cl.access IN (' . implode(',', JFactory::getUser()->getAuthorisedViewLevels()) . ')');

		if ($phrase === 'any' || $phrase === 'all')
		{
			$split = preg_split('/\s+/', $text);

			foreach ($split as $word)
			{
				$cond = '(i.title LIKE ' . $this->db->quote('%' . $word . '%', true);

				if ($this->params->get('search_issue_text', 1))
				{
					$cond .= ' OR i.text LIKE ' . $this->db->quote('%' . $word . '%', true);
				}

				$cond .= ')';

				$glue = ($phrase === 'any') ? 'OR' : 'AND';

				$query->where($cond, $glue);
			}
		}
		else
		{
			$query->where('i.title LIKE ' . $this->db->quote('%' . $text . '%', true), 'OR');

			if ($this->params->get('search_issue_text', 1))
			{
				$query->where('i.text LIKE ' . $this->db->quote('%' . $text . '%', true));
			}
		}

		switch ($ordering)
		{
			case 'alpha':
				$query->order('i.title');
				break;
			case 'category':
				$query->order('p.name');
				break;
			case 'oldest':
				$query->order('i.created ASC');
				break;

			// There is no such thing as popularity.
			case 'popular':
			case 'newest':
			default:
				$query->order('i.created DESC');
				break;
		}

		$this->db->setQuery($query)->execute();

		$rows = $this->db->loadObjectList();

		foreach ($rows as $row)
		{
			$row->href = 'index.php?option=com_monitor&view=issue&id=' . $row->id;
			$row->browsernav = $this->params->get('target');
		}

		return $rows;
	}

	/**
	 * Search for comments in the database.
	 *
	 * @param   string  $text      Target search string.
	 * @param   string  $phrase    Matching option (possible values: exact|any|all).  Default is "any".
	 * @param   string  $ordering  Ordering option (possible values: newest|oldest|popular|alpha|category).  Default is "newest".
	 *
	 * @return  array  Array consisting of objects with the attributes title, section, created, text, href and browsernav.
	 */
	private function searchComments($text, $phrase, $ordering)
	{
		$query = $this->db->getQuery(true);

		$query->select('c.id, c.created, c.text, c.issue_id, i.title, p.name AS section')
			->from('#__monitor_comments AS c')
			->leftJoin('#__monitor_issues AS i ON c.issue_id = i.id')
			->leftJoin('#__monitor_projects AS p ON i.project_id = p.id')
			->leftJoin('#__monitor_issue_classifications AS cl ON i.classification = cl.id');

		// ACL
		$query->where('cl.access IN (' . implode(',', JFactory::getUser()->getAuthorisedViewLevels()) . ')');

		if ($phrase === 'any' || $phrase === 'all')
		{
			$split = preg_split('/\s+/', $text);

			foreach ($split as $word)
			{
				$glue = ($phrase === 'any') ? 'OR' : 'AND';

				$query->where('c.text LIKE ' . $this->db->quote('%' . $word . '%', true), $glue);
			}
		}
		else
		{
			$query->where('c.text LIKE ' . $this->db->quote('%' . $text . '%', true));
		}

		switch ($ordering)
		{
			case 'alpha':
				$query->order('i.title');
				break;
			case 'category':
				$query->order('p.name');
				break;
			case 'oldest':
				$query->order('i.created ASC');
				break;

			// There is no such thing as popularity.
			case 'popular':
			case 'newest':
			default:
				$query->order('i.created DESC');
				break;
		}

		$this->db->setQuery($query)->execute();

		$rows = $this->db->loadObjectList();

		foreach ($rows as $row)
		{
			$row->title = 'Re: ' . $row->title;
			$row->href = 'index.php?option=com_monitor&view=issue&id=' . $row->issue_id . '#comment-' . $row->id;
			$row->browsernav = $this->params->get('target');
		}

		return $rows;
	}
}
