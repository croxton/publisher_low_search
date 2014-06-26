<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * ExpressionEngine Publisher Low Search Extension Class
 *
 * @package     ExpressionEngine
 * @subpackage  Extension
 * @category    Publisher
 * @author      Brian Litzinger
 * @copyright   Copyright (c) 2012, 2013 - Brian Litzinger
 * @link        http://boldminded.com/add-ons/publisher
 * @license
 *
 * Copyright (c) 2012, 2013. BoldMinded, LLC
 * All rights reserved.
 *
 * This source is commercial software. Use of this software requires a
 * site license for each domain it is used on. Use of this software or any
 * of its source code without express written permission in the form of
 * a purchased commercial or other license is prohibited.
 *
 * THIS CODE AND INFORMATION ARE PROVIDED "AS IS" WITHOUT WARRANTY OF ANY
 * KIND, EITHER EXPRESSED OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND/OR FITNESS FOR A
 * PARTICULAR PURPOSE.
 *
 * As part of the license agreement for this software, all modifications
 * to this source must be submitted to the original author for review and
 * possible inclusion in future releases. No compensation will be provided
 * for patches, although where possible we will attribute each contribution
 * in file revision notes. Submitting such modifications constitutes
 * assignment of copyright to the original author (Brian Litzinger and
 * BoldMinded, LLC) for such modifications. If you do not wish to assign
 * copyright to the original author, your license to  use and modify this
 * source is null and void. Use of this software constitutes your agreement
 * to this clause.
 */

class Publisher_low_search_ext {

    public $settings        = array();
    public $description     = 'Adds Low Search support to Publisher';
    public $docs_url        = '';
    public $name            = 'Publisher - Low Search Support';
    public $settings_exist  = 'n';
    public $version         = '1.0.5';

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
        if (isset($this->cache['batch_indexing']) && isset($entry['publisher_lang_id']))
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

        $field_names = array('t.entry_id', 't.channel_id');

        foreach ($fields as $k => $field_id)
        {
            // Skip non-numeric settings
            if ( !is_numeric($field_id)) continue;

            $field_names[] = ($field_id == 0) ? 't.title AS field_id_0' : 'd.field_id_'.$field_id;
        }

        // --------------------------------------
        // Build query
        // --------------------------------------

        // @todo - how do we handle a collection that has a combination of ignored and not ignored channels?
        if (count($channel_ids) == 1 && $this->EE->publisher_model->is_ignored_channel($channel_ids[0]))
        {
            $this->EE->db->select(implode(', ', $field_names))
                     ->from('channel_titles t')
                     ->join('channel_data d', 't.entry_id = d.entry_id', 'inner')
                     ->where_in('t.channel_id', $channel_ids);
        }
        else
        {
            $field_names[] = 't.publisher_lang_id';
            $field_names[] = 't.publisher_status';

            $this->EE->db->select(implode(', ', $field_names))
                         ->from('publisher_titles t')
                         ->join('publisher_data d', 't.entry_id = d.entry_id AND t.publisher_lang_id = d.publisher_lang_id AND t.publisher_status = d.publisher_status', 'inner')
                         ->where_in('t.channel_id', $channel_ids);
        }

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
            // $this->EE->db->limit($batch_size, $start);
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
            foreach ($entries as $index => $entry)
            {
                foreach ($entry_cats as $entry_id => $cat_entry_data)
                {
                    foreach ($cat_entry_data as $lang_id => $cat_data)
                    {
                        // foreach ($lang_data as $status => $cat_data)
                        // {
                            if (
                                $entry_id == $entry['entry_id'] &&
                                $lang_id == $entry['publisher_lang_id']
                                // && $status == $entry['publisher_status']
                            ){
                                $entries[$index] += $cat_data;
                            }
                        // }
                    }
                }
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
        $entry_ids = array();

        foreach ($entries as $entry)
        {
            $entry_ids[] = $entry["entry_id"];
        }

        // --------------------------------------
        // Two options: either get cats by their entry id,
        // or get details for given cat ids.
        // Compose query based on those two options.
        // --------------------------------------

        $ok     = FALSE;
        $select = array('cp.*', 'c.*', 'fd.*');
        $joins  = array(
            array('exp_categories c', 'c.cat_id = cp.cat_id', 'left'),
            array('exp_category_field_data fd', 'c.cat_id = fd.cat_id', 'left'),
            array('exp_publisher_categories pc', 'pc.cat_id = cp.cat_id', 'left outer')

        );
        $where  = array();

        if (is_array($entry_ids) && ! empty($entry_ids))
        {
            // Option 1: get categories by given entry_ids
            $ok = TRUE;
            $select[] = 'cp.entry_id';
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
        ee()->db->from('publisher_category_posts cp');

        // Process joins
        foreach ($joins as $join)
        {
            list($table, $on, $type) = $join;
            ee()->db->join($table, $on, $type);
        }

        // Process wheres
        foreach ($where as $key => $val)
        {
            ee()->db->where_in($key, $val);
        }

        $sql = ee()->db->_compile_select();
        ee()->db->_reset_select();

        $sql .= ' GROUP BY `cp`.`entry_id`, `cp`.`cat_id`, `cp`.`publisher_lang_id`, `cp`.`publisher_status`';

        // ee()->publisher_log->to_file($sql);
        /*

        // Original
        SELECT c.*, fd.*, cp.entry_id
        FROM (`exp_publisher_categories` c)
        LEFT JOIN `exp_category_field_data` fd ON `c`.`cat_id` = `fd`.`cat_id`
        INNER JOIN `exp_publisher_category_posts` cp ON `c`.`cat_id` = `cp`.`cat_id` AND c.publisher_lang_id = cp.publisher_lang_id
        WHERE `cp`.`entry_id` IN (19)

        // What we need for Publisher data
        SELECT cp.*, c.*, fd.*, cp.entry_id
        FROM (`exp_publisher_category_posts` cp)
        LEFT JOIN `exp_categories` c ON `c`.`cat_id` = `cp`.`cat_id`
        LEFT JOIN `exp_publisher_categories` pc ON `pc`.`cat_id` = `cp`.`cat_id`
        LEFT OUTER JOIN `exp_category_field_data` fd ON `c`.`cat_id` = `fd`.`cat_id`
            AND pc.publisher_lang_id = cp.publisher_lang_id
            AND pc.publisher_status = cp.publisher_status
        WHERE `cp`.`entry_id` IN (19....)
        GROUP BY `cp`.`entry_id`, `cp`.`cat_id`, `cp`.`publisher_lang_id`, `cp`.`publisher_status`;
         */

        $languages = ee()->publisher_model->languages;

        // --------------------------------------
        // Done with the query; loop through results
        // --------------------------------------

        // Relevant non-custom fields
        $fields = array('cat_name', 'cat_description');

        foreach ($languages as $lang_id => $language)
        {
            // foreach (array(PUBLISHER_STATUS_OPEN, PUBLISHER_STATUS_DRAFT) as $status)
            // {
                $query = ee()->publisher_query->modify(
                    'WHERE',
                    ' AND cp.publisher_lang_id = '. $lang_id .' AND cp.publisher_status = \''. PUBLISHER_STATUS_OPEN .'\' WHERE',
                    $sql
                );

                foreach ($query->result_array() as $row)
                {
                    // Loop through each result and populate the output
                    foreach ($row as $key => $val)
                    {
                        // Skip non-valid fields
                        if ( ! in_array($key, $fields) && ! preg_match('/^field_id_(\d+)$/', $key, $match)) continue;

                        // We're OK! Go on with composing the right key:
                        // Either the name or description or custom field ID
                        $cat_field = $match ? 'field_id_'.$match[1] : $key;

                        // $cats[$row['entry_id']][$lang_id][$status]["{$row['group_id']}:{$cat_field}"][$row['cat_id']] = $val;
                        $cats[$row['entry_id']][$lang_id]["{$row['group_id']}:{$cat_field}"][$row['cat_id']] = $val;
                    }
                }
            // }
        }

        ee()->db->_reset_select();

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
        // Get the excerpt no matter what since low search displays nothing otherwise
        $field_name = ($eid == 0) ? 'title' : 'field_id_'.$eid;

        $excerpt = $this->EE->publisher_model->get_field_value(
            $row['entry_id'],
            $field_name,
            $this->EE->publisher_lib->status,
            $this->EE->publisher_lib->lang_id
        );

        // ensure excerpt is a string
        $excerpt = is_array($excerpt) ? '' : $excerpt;

        // Try to find the default data
        if(ee()->publisher_setting->show_fallback() && $excerpt == "")
        {
            $excerpt = $this->EE->publisher_model->get_field_value(
                $row['entry_id'],
                $field_name,
                $this->EE->publisher_lib->status,
                $this->EE->publisher_lib->default_lang_id
            );

            // ensure excerpt is a string
            $excerpt = is_array($excerpt) ? '' : $excerpt;
        }

        return $excerpt;
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

        // Delete non-default language & open indexes
        $this->EE->db->where('publisher_lang_id !=', $this->EE->publisher_lib->default_lang_id)
                     ->delete('low_search_indexes');

        $this->EE->db->where('publisher_status', PUBLISHER_STATUS_DRAFT)
                     ->delete('low_search_indexes');

        // Drop our columns
        if ($this->EE->db->table_exists($this->table) AND $this->EE->db->field_exists('publisher_lang_id', $this->table))
        {
            $this->EE->dbforge->drop_column($this->table, 'publisher_status');
            $this->EE->dbforge->drop_column($this->table, 'publisher_lang_id');
        }

        // Finally, reset the original primary keys.
        $this->EE->db->query("ALTER TABLE `{$this->EE->db->dbprefix}{$this->table}` DROP PRIMARY KEY");
        $this->EE->db->query("ALTER TABLE `{$this->EE->db->dbprefix}{$this->table}` ADD PRIMARY KEY (collection_id, entry_id)");
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

/* End of file ext.publisher_low_search.php */
/* Location: /system/expressionengine/third_party/publisher_low_search/ext.publisher_low_search.php */
