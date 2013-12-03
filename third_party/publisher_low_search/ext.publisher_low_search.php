<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * ExpressionEngine - by EllisLab
 *
 * @package     ExpressionEngine
 * @author      ExpressionEngine Dev Team
 * @copyright   Copyright (c) 2003 - 2011, EllisLab, Inc.
 * @license     http://expressionengine.com/user_guide/license.html
 * @link        http://expressionengine.com
 * @since       Version 2.0
 * @filesource
 */
 
// ------------------------------------------------------------------------

/**
 * Low Search Publisher Support Extension
 *
 * @package     ExpressionEngine
 * @subpackage  Addons
 * @category    Extension
 * @author      Brian Litzinger
 * @link        http://boldminded.com
 */

class Publisher_low_search_ext {
    
    public $settings        = array();
    public $description     = 'Adds Low Search support to Publisher';
    public $docs_url        = '';
    public $name            = 'Publisher - Low Search Support';
    public $settings_exist  = 'n';
    public $version         = '1.0.2';

    private $table          = 'low_search_indexes';
    private $EE;
    
    /**
     * Constructor
     *
     * @param   mixed   Settings array or empty string if none exist.
     */
    public function __construct($settings = '')
    {
        $this->EE =& get_instance();

        // Create cache
        if (! isset($this->EE->session->cache['publisher_low_search']))
        {
            $this->EE->session->cache['publisher_low_search'] = array();
        }
        $this->cache =& $this->EE->session->cache['publisher_low_search'];
    }

    public function low_search_update_index($params, $entry = array())
    {
        // If batch indexing, just take the params from the entry row
        if (isset($this->cache['batch_indexing'])) 
        {
            $params['publisher_lang_id'] = $entry['publisher_lang_id'];
            $params['publisher_status'] = $entry['publisher_status'];
        }
        // Otherise take it from the current data
        else
        {
            // This isn't set yet when indexing via the ajax method, so just force to Open
            $status = isset($this->EE->publisher_lib->save_status) ? $this->EE->publisher_lib->save_status : PUBLISHER_STATUS_OPEN;
            
            $params['publisher_lang_id'] = $this->EE->publisher_lib->lang_id;
            $params['publisher_status']  = $status;
        }

        return $params;
    }

    public function low_search_pre_search($params)
    {
        $params['add_to_query'] = array(
            'publisher_lang_id' => $this->EE->publisher_lib->lang_id,
            'publisher_status'  => $this->EE->publisher_lib->status
        );

        return $params;
    }

    public function low_search_get_index_entries($fields, $channel_ids, $entry_ids, $start, $batch_size)
    {
        $this->cache['batch_indexing'] = TRUE;

        $field_names = array('t.entry_id', 't.channel_id', 't.publisher_lang_id', 't.publisher_status');

        foreach ($fields as $k => $field_id)
        {
            // Skip non-numeric settings
            if ( !is_numeric($field_id)) continue;

            $field_names[] = ($field_id == 0) ? 't.title AS field_id_0' : 'd.field_id_'.$field_id;
        }

        // --------------------------------------
        // Build query
        // --------------------------------------
        $fields[] = 't.publisher_lang_id';
        $fields[] = 't.publisher_status';

        $this->EE->db->select(implode(', ', $field_names))
                     ->from('publisher_titles t')
                     ->join('publisher_data d', 't.entry_id = d.entry_id AND t.publisher_lang_id = d.publisher_lang_id AND t.publisher_status = d.publisher_status', 'inner')
                     ->where_in('t.channel_id', $channel_ids);

        // --------------------------------------
        // Limit to given entries
        // --------------------------------------

        if ($entry_ids)
        {
            $this->EE->db->where_in('t.entry_id', $entry_ids);
        }

        // --------------------------------------
        // Limit entries by batch size, if given
        // --------------------------------------

        if ($start !== FALSE && is_numeric($start))
        {
            $this->EE->db->limit($batch_size, $start);
        }

        // --------------------------------------
        // Order it, just in case
        // --------------------------------------

        $this->EE->db->order_by('t.entry_id', 'asc');

        // --------------------------------------
        // Get it
        // --------------------------------------

        $entries = $this->EE->db->get()->result_array();

        // --------------------------------------
        // Get category info for these entries
        // --------------------------------------

        if ($entry_cats = $this->get_entry_categories($entries))
        {
            // add the categories to the index_entries rows
            foreach ($entry_cats AS $entry_id => $cats)
            {
                $entries[$entry_id] += $cats;
            }
        }

        // ee()->publisher_log->to_file($entries);

        return $entries;
    }

    /**
     * Get categories for entries
     *
     * @access     public
     * @param      mixed [int|array]
     * @param      mixed [null|array]
     * @return     array
     */
    private function get_entry_categories($entries, $cat_ids = NULL)
    {
        // Prep output
        $cats = array();
        $entry_ids = array_keys($entries);

        // --------------------------------------
        // Two options: either get cats by their entry id,
        // or get details for given cat ids.
        // Compose query based on those two options.
        // --------------------------------------

        $ok     = FALSE;
        $select = array('c.*');
        $joins  = array();
        $where  = array();

        if (is_array($entry_ids) && ! empty($entry_ids))
        {
            // Option 1: get categories by given entry_ids
            $ok = TRUE;
            $select[] = 'cp.entry_id';
            $joins[] = array('category_posts cp', 'c.cat_id = cp.cat_id', 'inner');
            $where['cp.entry_id'] = $entry_ids;
        }
        elseif (is_array($cat_ids) && ! empty($cat_ids))
        {
            // Option 2: get categories by given cat_ids,
            // hardcode entry ID to be compatible
            $ok = TRUE;
            $select[] = "'{$entry_ids}' AS `entry_id`";
            $where['c.cat_id'] = $cat_ids;
        }

        // Not ok? Bail out
        if ( ! $ok) return $cats;

        // Start query
        ee()->db->select($select, FALSE);
        ee()->db->from('publisher_categories c');

        // Process joins
        foreach ($joins AS $join)
        {
            list($table, $on, $type) = $join;
            ee()->db->join($table, $on, $type);
        }

        // Process wheres
        foreach ($where AS $key => $val)
        {
            ee()->db->where_in($key, $val);
        }

        // Execute query
        $query = ee()->db->get();

        // ee()->publisher_log->to_file(ee()->db->last_query());
        $languages = ee()->publisher_model->languages;

        // --------------------------------------
        // Done with the query; loop through results
        // --------------------------------------
        
        // Relevant non-custom fields
        $fields = array('cat_name', 'cat_description');

        foreach ($query->result_array() AS $row)
        {
            // Loop through each result and populate the output
            foreach ($row AS $key => $val)
            {
                // Skip non-valid fields
                if ( ! in_array($key, $fields) && ! preg_match('/^field_id_(\d+)$/', $key, $match)) continue;

                // We're OK! Go on with composing the right key:
                // Either the name or description or custom field ID
                $cat_field = $match ? $match[1] : $key;

                foreach (array(PUBLISHER_STATUS_OPEN, PUBLISHER_STATUS_DRAFT) as $status)
                {
                    $category = ee()->publisher_category->get_translations($row['cat_id'], $row['group_id'], $status);

                    // ee()->publisher_log->to_file('========== $category ==========');
                    // ee()->publisher_log->to_file($category);

                    foreach ($languages as $lang_id => $language)
                    {
                        $val = isset($category[$lang_id]->$cat_field) ? $category[$lang_id]->$cat_field : FALSE;

                        // ee()->publisher_log->to_file($val);

                        // Use that as the key in the array to return
                        if ($val)
                        {
                            $cats[$row['entry_id']]["{$row['group_id']}:{$cat_field}"][$row['cat_id']] = $val;
                        }
                    }
                }
            }
        }

        // --------------------------------------
        // Focus on the single one if one entry_id is given
        // --------------------------------------

        if ( ! is_array($entry_ids))
        {
            $cats = $cats[$entry_ids];
        }

        return $cats;
    }

    public function low_search_excerpt($entry_ids, $row, $eid)
    {
        // If its not the default language, get the translated value of the field to update
        // the excerpt string.
        if ($this->EE->publisher_lib->lang_id != $this->EE->publisher_lib->default_lang_id)
        {
            $field_name = ($eid == 0) ? 'title' : 'field_id_'.$eid;

            $excerpt = $this->EE->publisher_model->get_field_value(
                $row['entry_id'], 
                $field_name, 
                $this->EE->publisher_lib->status, 
                $this->EE->publisher_lib->lang_id
            );

            // ensure excerpt is a string
            $excerpt = is_array($excerpt) ? '' : $excerpt;

            // Might need to change to return $excerpt;
            return array($excerpt, FALSE);
        }

        return '';
    }

    /**
     * Activate Extension
     *
     * @return void
     */
    public function activate_extension()
    {
        // Setup custom settings in this array.
        $this->settings = array();
        
        // Add new hooks
        $ext_template = array(
            'class'    => __CLASS__,
            'settings' => serialize($this->settings),
            'priority' => 5,
            'version'  => $this->version,
            'enabled'  => 'y'
        );

        $extensions = array(
            array('hook'=>'low_search_update_index', 'method'=>'low_search_update_index'),
            array('hook'=>'low_search_get_index_entries', 'method'=>'low_search_get_index_entries'),
            array('hook'=>'low_search_pre_search', 'method'=>'low_search_pre_search'),
            array('hook'=>'low_search_excerpt', 'method'=>'low_search_excerpt')
        );

        foreach($extensions as $extension)
        {
            $this->EE->db->insert('extensions', array_merge($ext_template, $extension));
        }       

        $this->EE->load->dbforge();

        if ($this->EE->db->table_exists($this->table) AND ! $this->EE->db->field_exists('publisher_lang_id', $this->table)) 
        {
            $this->EE->db->query("ALTER TABLE `{$this->EE->db->dbprefix}{$this->table}` ADD COLUMN `publisher_lang_id` int(4) NOT NULL DEFAULT {$this->EE->publisher_lib->default_lang_id} AFTER `site_id`");
            $this->EE->db->query("ALTER TABLE `{$this->EE->db->dbprefix}{$this->table}` ADD COLUMN `publisher_status` varchar(24) NULL DEFAULT '". PUBLISHER_STATUS_OPEN ."' AFTER `publisher_lang_id`");

            $this->EE->db->query("ALTER TABLE `{$this->EE->db->dbprefix}{$this->table}` DROP PRIMARY KEY");
            $this->EE->db->query("ALTER TABLE `{$this->EE->db->dbprefix}{$this->table}` ADD PRIMARY KEY (collection_id, entry_id, publisher_lang_id, publisher_status)");
        }
    }   

    // ----------------------------------------------------------------------
    
    /**
     * Disable Extension
     *
     * This method removes information from the exp_extensions table
     *
     * @return void
     */
    function disable_extension()
    {
        $this->EE->db->where('class', __CLASS__);
        $this->EE->db->delete('extensions');

        $this->EE->db->where('publisher_status !=', PUBLISHER_STATUS_OPEN)
                     ->where('publisher_lang_id !=', $this->EE->publisher_lib->default_lang_id)
                     ->delete('low_search_indexes');

        if ($this->EE->db->table_exists($this->table) AND $this->EE->db->field_exists('publisher_lang_id', $this->table)) 
        {
            $this->EE->dbforge->drop_column($this->table, 'publisher_status');
            $this->EE->dbforge->drop_column($this->table, 'publisher_lang_id');
        }
    }

    // ----------------------------------------------------------------------

    /**
     * Update Extension
     *
     * This function performs any necessary db updates when the extension
     * page is visited
     *
     * @return  mixed   void on update / false if none
     */
    function update_extension($current = '')
    {
        if ($current == '' OR $current == $this->version)
        {
            return FALSE;
        }
    }   
    
    // ----------------------------------------------------------------------
}

/* End of file ext.navee_publisher.php */
/* Location: /system/expressionengine/third_party/navee_publisher/ext.navee_publisher.php */
