<?php

/*
    Copyright 2016 Altin Ukshini <altin.ukshini@gmail.com>
    
    This file is part of the Seltzer CRM Project
    transaction.inc.php - transaction tracking module
    
    Seltzer is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    any later version.
    
    Seltzer is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
    
    You should have received a copy of the GNU General Public License
    along with Seltzer.  If not, see <http://www.gnu.org/licenses/>.

    Seltzer project on github: https://github.com/elplatt/seltzer
*/

/**
 * @return This module's revision number.  Each new release should increment
 * this number.
 */
function transaction_revision () {
    return 1;
}

/**
 * @return An array of the permissions provided by this module.
 */
function transaction_permissions () {
    return array(
        'transaction_view'
        , 'transaction_edit'
        , 'transaction_delete'
    );
}

/**
 * Install or upgrade this module.
 * @param $old_revision The last installed revision of this module, or 0 if the
 *   module has never been installed.
 */
function transaction_install($old_revision = 0) {
    // Create initial database table
    if ($old_revision < 1) {
        $sql = '
            CREATE TABLE IF NOT EXISTS `transaction` (
              `trnid` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
              `date` date DEFAULT NULL,
              `description` varchar(255) NOT NULL,
              `code` varchar(8) NOT NULL,
              `value` mediumint(8) NOT NULL,
              `type` varchar(255) NOT NULL,
              `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`trnid`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
        ';
        $res = mysql_query($sql);
        if (!$res) crm_error(mysql_error());
        // Set default permissions
        $roles = array(
            '1' => 'authenticated'
            , '2' => 'member'
            , '3' => 'director'
            , '4' => 'president'
            , '5' => 'vp'
            , '6' => 'secretary'
            , '7' => 'treasurer'
            , '8' => 'webAdmin'
        );
        $default_perms = array(
            'director' => array('transaction_view', 'transaction_edit', 'transaction_delete')
            , 'webAdmin' => array('transaction_view', 'transaction_edit', 'transaction_delete')
        );
        foreach ($roles as $rid => $role) {
            $esc_rid = mysql_real_escape_string($rid);
            if (array_key_exists($role, $default_perms)) {
                foreach ($default_perms[$role] as $perm) {
                    $esc_perm = mysql_real_escape_string($perm);
                    $sql = "INSERT INTO `role_permission` (`rid`, `permission`) VALUES ('$esc_rid', '$esc_perm')";
                    $res = mysql_query($sql);
                    if (!$res) die(mysql_error());
                }
            }
        }
    }
}

// Utility functions ///////////////////////////////////////////////////////////

/**
 * Parse a string and return a currency.
 * @param $value A string representation of a currency value.
 * @param $code Optional currency code.
 */
function transaction_parse_currency ($value, $code = null) {
    global $config_currency_code;
    if (!isset($code)) {
        $code = $config_currency_code ? $config_currency_code : 'USD';
    }
    // Determine sign
    $sign = 1;
    if (preg_match('/^\(.*\)$/', $value) || preg_match('/^\-/', $value)) {
        $sign = -1;
    }
    // Remove all irrelevant characters
    switch ($code) {
        case 'SGD':
        case 'USD':
            $to_remove = '/[^0-9\.]/';
            break;
        case 'GBP':
            $to_remove = '/[^0-9\.]/';
            break;
        case 'EUR':
            $to_remove = '/[^0-9\.]/';
            break;
        default:
            $to_remove = '//';
    }
    $clean_value = preg_replace($to_remove, '', $value);
    // Split the amount into parts
    $count = 0;
    switch ($code) {
        case 'SGD':
        case 'USD':
            $parts = explode('\.', $clean_value);
            $dollars = $parts[0];
            $count = 100 * $dollars;
            if (count($parts) > 1 && !empty($parts[1])) {
                if (strlen($parts[1]) < 2) {
                    error_register("Warning: parsing of cents failed: '$parts[1]'");
                }
                $count += intval($parts[1]{0})*10 + intval($parts[1]{1});
            }
            break;
        case 'GBP':
            $parts = explode('\.', $clean_value);
            $pounds = $parts[0];
            $count = 100 * $pounds;
            if (count($parts) > 1 && !empty($parts[1])) {
                // This assumes there are exactly two digits worth of pence
                if (strlen($parts[1]) != 2) {
                    error_register("Warning: parsing of pence failed: '$parts[1]'");
                }
                $count += intval($parts[1]);
            }
            break;
        case 'EUR':
            $parts = explode('\.', $clean_value);
            $euros = $parts[0];
            $count = 100 * $euros;
            if (count($parts) > 1 && !empty($parts[1])) {
                // This assumes there are exactly two digits worth of cents
                if (strlen($parts[1]) != 2) {
                    error_register("Warning: parsing of cents failed: '$parts[1]'");
                }
                $count += intval($parts[1]);
            }
            break;
    }
    // Construct currency structure
    $currency_value = array(
        'code' => $code
        , 'value' => $count * $sign
    );
    return $currency_value;
}

/**
 * Format a currency.
 * @param $value A currency structure.
 * @param $symbol If true, include symbol (default true).
 * @return A string representation of $value.
 */
function transaction_format_currency ($value, $symbol = true) {
    $result = '';
    $count = $value['value'];
    $sign = 1;
    if ($count < 0) {
        $count *= -1;
        $sign = -1;
    }
    switch ($value['code']) {
        case 'SGD':
        case 'USD':
            if (strlen($count) > 2) {
                $dollars = substr($count, 0, -2);
                $cents = substr($count, -2);
            } else {
                $dollars = '0';
                $cents = sprintf('%02d', $count);
            }
            if ($symbol) {
                $result .= '$';
            }
            $result .= $dollars . '.' . $cents;
            if ($sign < 0) {
                $result = '(' . $result . ')';
            }
            break;
        case 'GBP':
            if (strlen($count) > 2) {
                $pounds = substr($count, 0, -2);
                $pence = substr($count, -2);
            } else {
                $pounds = '0';
                $pence = sprintf('%02d', $count);
            }
            if ($symbol) {
                $result .= '£';
            }
            $result .= $pounds . '.' . $pence;
            if ($sign < 0) {
                $result = '(' . $result . ')';
            }
            break;
        case 'EUR':
            if (strlen($count) > 2) {
                $euros = substr($count, 0, -2);
                $cents = substr($count, -2);
            } else {
                $euros = '0';
                $cents = sprintf('%02d', $count);
            }
            if ($symbol) {
                $result .= '€';
            }
            $result .= $euros . '.' . $cents;
            if ($sign < 0) {
                $result = '(' . $result . ')';
            }
            break;
        default:
            $result = $value['value'];
    }
    return $result;
}

/**
 * Add two currency values.
 * @param $a A currency structure.
 * @param $b A currency structure.
 * @return The sum of $a and $b
 */
function transaction_add_currency ($a, $b) {
    if ($a['code'] != $b['code']) {
        error_register('Attempted to add currencies of different types');
        return array();
    }
    return array(
        'code' => $a['code']
        , 'value' => $a['value'] + $b['value']
    );
}

/**
 * Inverts a currency value.
 * @param $value The currency value.
 * @return A copy of $value with the amount multiplied by -1.
 */
function transaction_invert_currency ($value) {
    $value['value'] *= -1;
    return $value;
}

// DB to Object mapping ////////////////////////////////////////////////////////

/**
 * Return data for one or more transactions.
 *
 * @param $opts An associative array of options, possible keys are:
 *   'trnid' If specified, returns a single transaction with the matching id;
 *   'cid' If specified, returns all transactions assigned to the contact with specified id;
 *   'filter' An array mapping filter names to filter values;
 *   'join' A list of tables to join to the transaction table;
 *   'order' An array of associative arrays of the form 'field'=>'order'.
 * @return An array with each element representing a single transaction.
*/ 
function transaction_data ($opts = array()) {
    $sql = "
        SELECT
        `trnid`
        , `date`
        , `description`
        , `code`
        , `value`
        , `type`
        FROM `transaction`
    ";
    $sql .= "WHERE 1 ";
    if (array_key_exists('trnid', $opts)) {
        $trnid = mysql_real_escape_string($opts['trnid']);
        $sql .= " AND `trnid`='$trnid' ";
    }

    if (array_key_exists('filter', $opts) && !empty($opts['filter'])) {
        foreach($opts['filter'] as $name => $value) {
            $esc_value = mysql_real_escape_string($value);
            switch ($name) {
                case 'type':
                    $sql .= " AND `type`='$esc_value' ";
                    break;
            }
        }
    }
    // Specify the order the results should be returned in
    if (isset($opts['order'])) {
        $field_list = array();
        foreach ($opts['order'] as $field => $order) {
            $clause = '';
            switch ($field) {
                case 'date':
                    $clause .= "`date` ";
                    break;
                case 'created':
                    $clause .= "`created` ";
                    break;
                default:
                    continue;
            }
            if (strtolower($order) === 'asc') {
                $clause .= 'ASC';
            } else {
                $clause .= 'DESC';
            }
            $field_list[] = $clause;
        }
        if (!empty($field_list)) {
            $sql .= " ORDER BY " . implode(',', $field_list) . " ";
        }
    } else {
        // Default to date, created from newest to oldest
        $sql .= " ORDER BY `date` DESC, `created` DESC ";
    }
    $res = mysql_query($sql);
    if (!$res) crm_error(mysql_error());
    $transactions = array();
    $row = mysql_fetch_assoc($res);
    while ($row) {
        $transaction = array(
            'trnid' => $row['trnid']
            , 'date' => $row['date']
            , 'description' => $row['description']
            , 'code' => $row['code']
            , 'value' => $row['value']
            , 'type' => $row['type']
        );
        $transactions[] = $transaction;
        $row = mysql_fetch_assoc($res);
    }
    return $transactions;
}

/**
 * Save a transaction to the database.  If the transaction has a key called "trnid"
 * an existing transaction will be updated in the database.  Otherwise a new transaction
 * will be added to the database.  If a new transaction is added to the database,
 * the returned array will have a "trnid" field corresponding to the database id
 * of the new transaction.
 * 
 * @param $transaction An associative array representing a transaction.
 * @return A new associative array representing the transaction.
 */
function transaction_save ($transaction) {
    // Verify permissions and validate input
    if (!user_access('transaction_edit')) {
        error_register('Permission denied: transaction_edit');
        return NULL;
    }
    if (empty($transaction)) {
        return NULL;
    }
    // Sanitize input
    $esc_trnid = mysql_real_escape_string($transaction['trnid']);
    $esc_date = mysql_real_escape_string($transaction['date']);
    $esc_description = mysql_real_escape_string($transaction['description']);
    $esc_code = mysql_real_escape_string($transaction['code']);
    $esc_value = mysql_real_escape_string($transaction['value']);
    $esc_type = mysql_real_escape_string($transaction['type']);
    // Query database
    if (array_key_exists('trnid', $transaction) && !empty($transaction['trnid'])) {
        // transaction already exists, update
        $sql = "
            UPDATE `transaction`
            SET
            `date`='$esc_date'
            , `description` = '$esc_description'
            , `code` = '$esc_code'
            , `value` = '$esc_value'
            , `type` = '$esc_type'
            WHERE
            `trnid` = '$esc_trnid'
        ";
        $res = mysql_query($sql);
        if (!$res) crm_error(mysql_error());
        $transaction = module_invoke_api('transaction', $transaction, 'update');
    } else {
        // transaction does not yet exist, create
        $sql = "
            INSERT INTO `transaction`
            (
                `date`
                , `description`
                , `code`
                , `value`
                , `type`
            )
            VALUES
            (
                '$esc_date'
                , '$esc_description'
                , '$esc_code'
                , '$esc_value'
                , '$esc_type'
            )
        ";
        $res = mysql_query($sql);
        if (!$res) crm_error(mysql_error());
        $transaction['trnid'] = mysql_insert_id();
        $transaction = module_invoke_api('transaction', $transaction, 'insert');
    }
    return $transaction;
}

/**
 * Delete the transaction identified by $trnid.
 * @param $trnid The transaction id.
 */
function transaction_delete ($trnid) {
    $transaction = crm_get_one('transaction', array('trnid'=>$trnid));
    $transaction = module_invoke_api('transaction', $transaction, 'delete');
    // Query database
    $esc_trnid = mysql_real_escape_string($trnid);
    $sql = "
        DELETE FROM `transaction`
        WHERE `trnid`='$esc_trnid'";
    $res = mysql_query($sql);
    if (!$res) crm_error(mysql_error());
    if (mysql_affected_rows() > 0) {
        message_register('Deleted transaction with id ' . $trnid);
    }
}



// Table data structures ///////////////////////////////////////////////////////

/**
 * Return a table structure for a table of transactions.
 *
 * @param $opts The options to pass to transaction_data().
 * @return The table structure.
*/
function transaction_table ($opts) {
    // Determine settings
    $export = false;
    foreach ($opts as $option => $value) {
        switch ($option) {
            case 'export':
                $export = $value;
                break;
        }
    }
    // Get transaction data
    $data = crm_get_data('transaction', $opts);
    if (count($data) < 1) {
        return array();
    }
    $table = array(
        "id" => '',
        "class" => '',
        "rows" => array(),
        "columns" => array()
    );
    // Add columns
    if (user_access('transaction_view')) { // Permission check
        $table['columns'][] = array("title"=>'date');
        $table['columns'][] = array("title"=>'description');
        $table['columns'][] = array("title"=>'amount');
        $table['columns'][] = array("title"=>'type');
    }
    // Add ops column
    if (!$export && (user_access('transaction_edit') || user_access('transaction_delete'))) {
        $table['columns'][] = array('title'=>'Ops','class'=>'');
    }
    // Add rows
    foreach ($data as $transaction) {
        $row = array();
        if (user_access('transaction_view')) {
            $row[] = $transaction['date'];
            $row[] = $transaction['description'];
            $row[] = transaction_format_currency($transaction, true);
            $row[] = $transaction['type'];
        }
        if (!$export && (user_access('transaction_edit') || user_access('transaction_delete'))) {
            // Add ops column
            $ops = array();
            if (user_access('transaction_edit')) {
               $ops[] = '<a href=' . crm_url('transaction&trnid=' . $transaction['trnid']) . '>edit</a>';
            }
            if (user_access('transaction_delete')) {
                $ops[] = '<a href=' . crm_url('delete&type=transaction&id=' . $transaction['trnid']) . '>delete</a>';
            }
            $row[] = join(' ', $ops);
        }
        $table['rows'][] = $row;
    }
    return $table;
}


// Forms ///////////////////////////////////////////////////////////////////////

/**
 * @return Array mapping transaction types.
 */
function transaction_type_options () {

    $options = array();
    $options['income'] = 'income';
    $options['expense'] = 'expense';
    return $options;
}

/**
 * @return The form structure for adding a transaction.
*/
function transaction_add_form () {
    
    // Ensure user is allowed to edit transactions
    if (!user_access('transaction_edit')) {
        return NULL;
    }
    
    // Create form structure
    $form = array(
        'type' => 'form'
        , 'method' => 'post'
        , 'command' => 'transaction_add'
        , 'fields' => array(
            array(
                'type' => 'fieldset'
                , 'label' => 'Add Transaction'
                , 'fields' => array(
                    array(
                        'type' => 'text'
                        , 'label' => 'Date'
                        , 'name' => 'date'
                        , 'value' => date("Y-m-d")
                        , 'class' => 'date float'
                    )
                    , array(
                        'type' => 'text'
                        , 'label' => 'Description'
                        , 'name' => 'description'
                        , 'class' => 'float'
                    )
                    , array(
                        'type' => 'text'
                        , 'label' => 'Amount'
                        , 'name' => 'amount'
                        , 'class' => 'float'
                    )
                    , array(
                        'type' => 'select'
                        , 'label' => 'Type'
                        , 'name' => 'type'
                        , 'options' => transaction_type_options()
                        , 'class' => 'float'
                    )
                    , array(
                        'type' => 'submit'
                        , 'value' => 'Add'
                    )
                )
            )
        )
    );
    
    return $form;
}

/**
 * Create a form structure for editing a transaction.
 *
 * @param $trnid The id of the transaction to edit.
 * @return The form structure.
*/
function transaction_edit_form ($trnid) {
    // Ensure user is allowed to edit transactions
    if (!user_access('transaction_edit')) {
        error_register('User does not have permission: transaction_edit');
        return NULL;
    }
    // Get transaction data
    $data = crm_get_data('transaction', array('trnid'=>$trnid));
    if (count($data) < 1) {
        return NULL;
    }
    $transaction = $data[0];

    // Create form structure
    $form = array(
        'type' => 'form'
        , 'method' => 'post'
        , 'command' => 'transaction_edit'
        , 'hidden' => array(
            'trnid' => $transaction['trnid']
            , 'code' => $transaction['code']
        )
        , 'fields' => array(
            array(
                'type' => 'fieldset'
                , 'label' => 'Edit Transaction'
                , 'fields' => array(
                    array(
                        'type' => 'text'
                        , 'label' => 'Date'
                        , 'name' => 'date'
                        , 'value' => $transaction['date']
                        , 'class' => 'date'
                    )
                    , array(
                        'type' => 'text'
                        , 'label' => 'Description'
                        , 'name' => 'description'
                        , 'value' => $transaction['description']
                    )
                    , array(
                        'type' => 'text'
                        , 'label' => 'Amount'
                        , 'name' => 'value'
                        , 'value' => transaction_format_currency($transaction, false)
                    )
                    , array(
                        'type' => 'select'
                        , 'label' => 'Type'
                        , 'name' => 'type'
                        , 'options' => transaction_type_options()
                        , 'selected' => $transaction['type']
                    )
                    , array(
                        'type' => 'submit'
                        , 'value' => 'Save'
                    )
                )
            )
        )
    );
    // Make data accessible for other modules modifying this form
    $form['data']['transaction'] = $transaction;
    return $form;
}

/**
 * Return the transaction form structure.
 *
 * @param $trnid The id of the key assignment to delete.
 * @return The form structure.
*/
function transaction_delete_form ($trnid) {
    // Ensure user is allowed to delete keys
    if (!user_access('transaction_delete')) {
        return NULL;
    }
    // Get data
    $data = crm_get_data('transaction', array('trnid'=>$trnid));
    $transaction = $data[0];
    // Construct key name
    $amount = transaction_format_currency($transaction);
    $transaction_name = "transaction: $transaction[trnid] - $amount";

    // Create form structure
    $form = array(
        'type' => 'form',
        'method' => 'post',
        'command' => 'transaction_delete',
        'hidden' => array(
            'trnid' => $transaction['trnid']
        ),
        'fields' => array(
            array(
                'type' => 'fieldset',
                'label' => 'Delete Transaction',
                'fields' => array(
                    array(
                        'type' => 'message',
                        'value' => '<p>Are you sure you want to delete the transaction "' . $transaction_name . '"? This cannot be undone.',
                    ),
                    array(
                        'type' => 'submit',
                        'value' => 'Delete'
                    )
                )
            )
        )
    );
    return $form;
}


/**
 * Return the form structure for a transaction filter.
 * @return The form structure.
 */
function transaction_filter_form () {
    // Available filters
    $filters = array(
        'all' => 'All',
        'income' => 'Income',
        'expense' => 'Expense'
    );
    // Default filter
    $selected = empty($_SESSION['transaction_filter_option']) ? 'all' : $_SESSION['transaction_filter_option'];
    // Construct hidden fields to pass GET params
    $hidden = array();
    foreach ($_GET as $key=>$val) {
        $hidden[$key] = $val;
    }
    $form = array(
        'type' => 'form'
        , 'method' => 'get'
        , 'command' => 'transaction_filter'
        , 'hidden' => $hidden,
        'fields' => array(
            array(
                'type' => 'fieldset'
                , 'label' => 'Filter'
                ,'fields' => array(
                    array(
                        'type' => 'select'
                        , 'name' => 'filter'
                        , 'options' => $filters
                        , 'selected' => $selected
                    ),
                    array(
                        'type' => 'submit'
                        , 'value' => 'Filter'
                    )
                )
            )
        )
    );
    return $form;
}

// Pages ///////////////////////////////////////////////////////////////////////

/**
 * @return An array of pages provided by this module.
 */
function transaction_page_list () {
    $pages = array();

    if (user_access('transaction_edit')) {
        $pages[] = 'transactions';
        $pages[] = 'transaction';
    }
    return $pages;
}

/**
 * Page hook.  Adds module content to a page before it is rendered.
 *
 * @param &$page_data Reference to data about the page being rendered.
 * @param $page_name The name of the page being rendered.
 * @param $options The array of options passed to theme('page').
*/
function transaction_page (&$page_data, $page_name, $options) {
    switch ($page_name) {
        case 'transactions':
            page_set_title($page_data, 'Transactions');
            if (user_access('transaction_edit')) {
                $filter = array_key_exists('transaction_filter', $_SESSION) ? $_SESSION['transaction_filter'] : '';
                $content = theme('form', crm_get_form('transaction_add'));
                $content .= theme('form', crm_get_form('transaction_filter'));
                $opts = array(
                    'show_export' => true
                    , 'filter' => $filter
                );
                $content .= theme('table', crm_get_table('transaction', $opts));
                page_add_content_top($page_data, $content, 'View');
            }
            break;
        case 'transaction':
            page_set_title($page_data, 'Transaction');
            if (user_access('transaction_edit')) {
                $content = theme('form', crm_get_form('transaction_edit', $_GET['trnid']));
                page_add_content_top($page_data, $content);
            }
            break;
    }
}

// Command handlers ////////////////////////////////////////////////////////////

/**
 * Handle transaction add request.
 *
 * @return The url to display on completion.
 */
function command_transaction_add() {
    $value = transaction_parse_currency($_POST['amount'], $_POST['code']);
    $transaction = array(
        'date' => $_POST['date']
        , 'description' => $_POST['description']
        , 'code' => $value['code']
        , 'value' => $value['value']
        , 'type' => $_POST['type']
    );
    $transaction = transaction_save($transaction);
    message_register('1 transaction added.');
    return crm_url('transactions');
}

/**
 * Handle transaction edit request.
 *
 * @return The url to display on completion.
 */
function command_transaction_edit() {
    // Verify permissions
    if (!user_access('transaction_edit')) {
        error_register('Permission denied: transaction_edit');
        return crm_url('transactions');
    }
    // Parse and save transaction
    $transaction = $_POST;
    $value = transaction_parse_currency($_POST['value'], $_POST['code']);
    $transaction['code'] = $value['code'];
    $transaction['value'] = $value['value'];
    transaction_save($transaction);
    message_register('1 transaction updated.');
    return crm_url('transactions');
}

/**
 * Handle transaction delete request.
 *
 * @return The url to display on completion.
 */
function command_transaction_delete() {
    global $esc_post;
    // Verify permissions
    if (!user_access('transaction_delete')) {
        error_register('Permission denied: transaction_delete');
        return crm_url('transaction&trnid=' . $esc_post['trnid']);
    }
    transaction_delete($_POST['trnid']);
    return crm_url('transactions');
}

/**
 * Handle transaction filter request.
 * @return The url to display on completion.
 */
function command_transaction_filter () {
    // Set filter in session
    $_SESSION['transaction_filter_option'] = $_GET['filter'];
    // Set filter
    if ($_GET['filter'] == 'all') {
        $_SESSION['transaction_filter'] = array();
    }
    if ($_GET['filter'] == 'income') {
        $_SESSION['transaction_filter'] = array('type'=>'income');
    }
    if ($_GET['filter'] == 'expense') {
        $_SESSION['transaction_filter'] = array('type'=>'expense');
    }
    
    // Construct query string
    $params = array();
    foreach ($_GET as $k=>$v) {
        if ($k == 'command' || $k == 'filter' || $k == 'q') {
            continue;
        }
        $params[] = urlencode($k) . '=' . urlencode($v);
    }
    if (!empty($params)) {
        $query = '&' . implode('&', $params);
    }
    return crm_url('transactions') . $query;
}
