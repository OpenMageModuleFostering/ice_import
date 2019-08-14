<?php

class ICEshop_Iceimport_Adminhtml_IceimportController extends Mage_Adminhtml_Controller_Action
{

    /**
     * indexAction
     *
     * @return void
     *
     * TODO prevent hardcoded html structure
     */
    public function systemAction()
    {
        $checker = Mage::helper('iceimport/system_systemcheck')->init();
        $helper = Mage::helper('iceimport');
        ob_start();
        ?>
        <?php
        //Problems Digest
        $problems_digest = $checker->getExtensionProblemsDigest();
        $problems = $problems_digest->getProblems();
        if ($problems_digest->getCount() > 0) :
            ?>
            <div class="entry-edit" id="iceimport-digest">
                <div class="entry-edit-head collapseable">
                    <a class="open section-toggler-iceimport"
                       href="#"><?php print $helper->__('Problems Digest'); ?></a>
                </div>

                <div class="fieldset">
                    <div class="hor-scroll">
                        <table class="form-list" cellspacing="0" cellpadding="0">
                            <?php print sprintf($helper->__('To guarantee the correct functioning of the Iceimport module you need to solve the following %s problems:'), '<strong class="requirement-failed">' . $problems_digest->getCount() . '</strong>'); ?>
                            <?php
                            $i = 1;
                            foreach ($problems as $problem_section_name => $problem_section) {
                                foreach ($problem_section as $problem_name => $problem_value) {
                                    print '<tr>';
                                    print '<td class="label">';
                                    print '<label class="problem-digest">' . $helper->__('Problem') . " " . $i . ':</label>';
                                    print '</td>';
                                    print '<td class="value">';
                                    if ($problem_section_name != 'iceimport_log') {
                                        print '<span class="requirement-passed">"' . $problem_value['label'] . '"</span> ' . $helper->__('current value is') . ' <span class="requirement-failed">"' . $problem_value['current_value'] . '"</span> ' . $helper->__('and recommended value is') . ' <span class="requirement-passed">"' . (!empty($problem_value['recommended_value']) ? $problem_value['recommended_value'] : '') . '"</span>. ' . $helper->__(' Check this parameter in') . ' <a class="section-toggler-trigger-iceimport requirement-passed" data-href="#' . $problem_section_name . '-section" href="#' . $problem_section_name . '-section">' . ucfirst($problem_section_name) . '</a> ' . $helper->__('section') . '.';
                                    } else {
                                        print '<span class="requirement-passed">"' . $problem_value['label'] . '"</span> <span class="requirement-failed">"' . $problem_value['current_value'] . '"</span>. ' . $helper->__(' Check ') . ' <a class="section-toggler-trigger-iceimport requirement-passed" data-href="#' . $problem_section_name . '-section" href="#' . $problem_section_name . '-section">' . ucfirst($problem_section_name) . '</a> ' . $helper->__('section') . '.';
                                    }
                                    print '</td>';
                                    print '</tr>';
                                    $i++;
                                }
                            }
                            ?>
                            <tr>
                                <td class="label col1">
                                    <label><?php print $helper->__("Report"); ?></label>
                                </td>
                                <td class="value col2" colspan="2">
                                    <a href="<?php print Mage::helper("adminhtml")->getUrl("adminhtml/iceimport/report/") ?>"
                                       target="_blank">&raquo;<?php print $helper->__('Click to generate'); ?></a>
                                    <p class="note"><?php print $helper->__("Use this report for more info on found problems or send it to Iceshop B.V. to help analyzing the problem to speed up solution of any issues."); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        <?php
        endif;
        //Check module
        $DB_checker = Mage::helper('iceimport/db');
        $data_flows = $DB_checker->getRowCountByField($DB_checker->getTableName('dataflow_batch_import'), 'batch_id', false, ' ORDER BY 1 DESC LIMIT 50');
        $currently_imported_products = $DB_checker->getRowsCount($DB_checker->_prefix . "iceshop_iceimport_imported_product_ids");
        $table_name = $DB_checker->getTableName('dataflow_profile_history');
        $last_started_by_cron = $DB_checker->getLogEntryByKey('iceimport_import_started');
        $last_finished_by_cron = $DB_checker->getLogEntryByKey('iceimport_import_ended');
        $import_status_cron = $DB_checker->getLogEntryByKey('iceimport_import_status_cron');
        $last_deleted_products_count = $DB_checker->getLogEntryByKey('iceimport_count_delete_product');
        $last_imported_products_count = $DB_checker->getLogEntryByKey('iceimport_count_imported_products');
        $last_run = $DB_checker->readQuery("SELECT `performed_at` FROM {$table_name} WHERE `profile_id` = 3 ORDER BY `performed_at` DESC LIMIT 1");
        ?>
        <span id="iceimport_statistics-section"></span>
        <div class="entry-edit">
            <div class="entry-edit-head collapseable">
                <a href="#" class="section-toggler-iceimport">
                    Iceimport Statistics
                </a>
            </div>

            <div class="fieldset iceimport-hidden">
                <div class="hor-scroll">
                    <table class="form-list" cellspacing="0" cellpadding="0">
                        <tr>
                            <td colspan="2" class="label">
                                <label class="iceimport-label-uppercase iceimport-label-bold">
                                    <?php print $helper->__('Import status by cron'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__("Started last time at"); ?>:</label></td>
                            <td class="value">
                                <?php if (!empty($last_started_by_cron['log_value'])) echo $last_started_by_cron['log_value']; else print $helper->__("Never started till now"); ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__("Finished last time at"); ?>:</label></td>
                            <td class="value">
                                <?php if (!empty($last_finished_by_cron['log_value'])) echo $last_finished_by_cron['log_value']; else print $helper->__("Never started till now"); ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__("Import cron process status"); ?>:</label>
                            </td>
                            <td class="value">
                                <?php if (!empty($import_status_cron['log_value'])) echo $import_status_cron['log_value']; else print $helper->__("Never started till now"); ?>
                            </td>
                        </tr>
                        <?php if (!empty($currently_imported_products) && ($import_status_cron['log_value'] == 'Running' || $import_status_cron['log_value'] == 'Failed')) { ?>
                            <tr>
                                <td class="label"><label><?php print $helper->__("Currently products imported"); ?>
                                        :</label></td>
                                <td class="value">
                                    <?php echo $currently_imported_products . ' from ' . $data_flows[0]['row_count']; ?>
                                </td>
                            </tr>
                        <?php } ?>
                        <tr>
                            <td colspan="2" class="label">
                                <label class="iceimport-label-uppercase iceimport-label-bold">
                                    <?php print $helper->__('Last import Statistics'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__("Products imported last time "); ?>
                                    :</label></td>
                            <td class="value">
                                <?php if (!empty($last_imported_products_count['log_value'])) echo $last_imported_products_count['log_value']; else echo "0"; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="label">
                                <label><?php print $helper->__("Removed out of date products last time "); ?>:</label>
                            </td>
                            <td class="value">
                                <?php if (!empty($last_deleted_products_count['log_value'])) echo $last_deleted_products_count['log_value']; else echo "0"; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <?php
        //Check module
        $check_module = $checker->getModulesCollection('ICEshop_Iceimport');
        $check_module = $check_module->getLastItem()->getData();
        ?>
        <span id="extension-section"></span>
        <div class="entry-edit">
            <div class="entry-edit-head collapseable">
                <a href="#"
                   class="section-toggler-iceimport"><?php print $helper->__('Extension Diagnostic Info'); ?></a>
            </div>
            <div class="fieldset iceimport-hidden">
                <div class="hor-scroll">
                    <table class="form-list" cellspacing="0" cellpadding="0">
                        <tr>
                            <td class="label"><label><?php print $helper->__('Name'); ?>:</label></td>
                            <td class="value"><?php echo $check_module['name']; ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Version'); ?>:</label></td>
                            <td class="value"><?php echo $check_module['version']; ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Code Pool'); ?>:</label></td>
                            <td class="value"><?php echo $check_module['code_pool']; ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Path'); ?>:</label></td>
                            <td class="value"><?php echo $check_module['path']; ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Path Exists'); ?>:</label></td>
                            <td class="value <?php echo $checker->renderRequirementValue($check_module['path_exists']); ?>">
                                <?php echo $checker->renderBooleanField($check_module['path_exists']); ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Config Exists'); ?>:</label></td>
                            <td class="value <?php echo $checker->renderRequirementValue($check_module['config_exists']); ?>">
                                <?php echo $checker->renderBooleanField($check_module['config_exists']); ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Dependencies'); ?>:</label></td>
                            <td class="value"><?php echo $check_module['dependencies']; ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <?php
        //Check rewrites
        $check_rewrites = $checker->getRewriteCollection('ICEshop_Iceimport');
        ?>
        <span id="rewrite-section"></span>
        <div class="entry-edit">
            <div class="entry-edit-head collapseable">
                <a href="#"
                   class="section-toggler-iceimport"><?php print $helper->__('Extension Rewrites Status'); ?></a>
            </div>

            <div class="fieldset iceimport-hidden">
                <div class="hor-scroll">
                    <table class="form-list" cellspacing="0" cellpadding="0">
                        <tbody>
                        <?php
                        foreach ($check_rewrites as $rewrite) {
                            ?>
                            <tr>
                                <td>
                                    <span
                                        class="iceimport-label-bold iceimport-label-rewrite"><?php print $helper->__('Path'); ?>
                                        :</span>
                                    <span><?php echo $rewrite['path']; ?></span>
                                    <br/>
                                    <span
                                        class="iceimport-label-bold iceimport-label-rewrite"><?php print $helper->__('Rewrite Class'); ?>
                                        :</span>
                                    <span><?php echo $rewrite['rewrite_class']; ?></span>
                                    <br/>
                                    <span
                                        class="iceimport-label-bold iceimport-label-rewrite"><?php print $helper->__('Active Class'); ?>
                                        :</span>
                                    <span><?php echo $rewrite['active_class']; ?></span>
                                    <br/>
                                    <span
                                        class="iceimport-label-bold iceimport-label-rewrite"><?php print $helper->__('Status'); ?>
                                        :</span>
                                    <span
                                        class="<?php echo $checker->renderRequirementValue($rewrite['status']); ?>">
                                                <?php echo $checker->renderStatusField($rewrite['status']); ?>
                                            </span>
                                    <br/>
                                    <br/>
                                </td>
                            </tr>
                        <?php
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <span id="requirement-section"></span>
        <div class="entry-edit">
            <div class="entry-edit-head collapseable">
                <a class="section-toggler-iceimport" href="#"><?php print $helper->__('System Requirements'); ?></a>
            </div>

            <?php $requirements = $checker->getSystem()->getRequirements()->getData(); ?>
            <div class="fieldset iceimport-hidden">
                <div class="hor-scroll">
                    <table class="form-list firegento-settings-table" cellspacing="0" cellpadding="0">
                        <thead>
                        <tr>
                            <th class="label col1"><?php print $helper->__('Requirement'); ?></th>
                            <th class="value col2"><?php print $helper->__('Current Value'); ?></th>
                            <th class="value col3"><?php print $helper->__('Recommended Value'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($requirements as $requirement): ?>
                            <tr>
                                <td class="label col1">
                                    <label><span
                                            class="iceimport-pad-label"><?php echo $requirement['label']; ?></span><?php print $checker->renderAdvice($requirement); ?>
                                        :</label>
                                </td>
                                <td class="value col2 <?php echo $checker->renderRequirementValue($requirement['result']) ?>">
                                    <?php echo $requirement['current_value'] ?>
                                </td>
                                <td class="value col3"><?php echo $requirement['recommended_value'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td class="label col1"><label>phpinfo()</label>
                            </td>
                            <td class="value col2" colspan="2">
                                <a href="<?php print Mage::helper("adminhtml")->getUrl("adminhtml/iceimport/phpinfo/") ?>"
                                   target="_blank">&raquo;<?php print $helper->__('More info'); ?></a>
                            </td>
                        </tr>
                        <?php ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <span id="magento-section"></span>
        <div class="entry-edit">
            <div class="entry-edit-head collapseable">
                <a href="#" class="section-toggler-iceimport">Magento Info</a>
            </div>

            <div class="fieldset iceimport-hidden">
                <div class="hor-scroll">
                    <table class="form-list" cellspacing="0" cellpadding="0">
                        <tr>
                            <td class="label"><label>Edition:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getMagento()->getEdition() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label>Version:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getMagento()->getVersion() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label>Developer Mode:</label></td>
                            <td class="value"><?php echo $checker->renderBooleanField($checker->getSystem()->getMagento()->getDeveloperMode()) ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label>Add Secret Key to URLs:</label></td>
                            <td class="value"><?php echo $checker->renderBooleanField($checker->getSystem()->getMagento()->getSecretKey()) ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label>Use Flat Catalog Category:</label></td>
                            <td class="value"><?php echo $checker->renderBooleanField($checker->getSystem()->getMagento()->getFlatCatalogCategory()) ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label>Use Flat Catalog Product:</label></td>
                            <td class="value"><?php echo $checker->renderBooleanField($checker->getSystem()->getMagento()->getFlatCatalogProduct()) ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label>Cache status:</label></td>
                            <td class="value">
                                <?php echo $checker->getSystem()->getMagento()->getCacheStatus() ?><br/>
                                <a href="<?php echo Mage::helper("adminhtml")->getUrl('adminhtml/cache/') ?>">&raquo;Cache
                                    Management</a>
                            </td>
                        </tr>
                        <tr>
                            <td class="label"><label>Index status:</label></td>
                            <td class="value">
                                <?php echo $checker->getSystem()->getMagento()->getIndexStatus() ?><br/>
                                <a href="<?php echo Mage::helper("adminhtml")->getUrl('adminhtml/process/list') ?>">&raquo;Index
                                    Management</a>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <span id="api-section"></span>
        <div class="entry-edit">
            <div class="entry-edit-head collapseable">
                <a href="#" class="section-toggler-iceimport">Magento Core API Info</a>
            </div>

            <div class="fieldset iceimport-hidden">
                <div class="hor-scroll">
                    <table class="form-list" cellspacing="0" cellpadding="0">
                        <tr>
                            <td class="label"><label><?php print $helper->__('Default Response Charset'); ?>:</label>
                            </td>
                            <td class="value"><?php echo $checker->getSystem()->getMagentoApi()->getCharset() ?></td>
                        </tr>
                        <tr>
                            <?php $magento_api_session_timeout = $checker->getSystem()->getMagentoApi()->getSessionTimeout() ?>
                            <td class="label"><label><?php print $magento_api_session_timeout['label']; ?>:</label></td>
                            <td class="value <?php echo $checker->renderRequirementValue($magento_api_session_timeout['result']) ?>"><?php echo $magento_api_session_timeout['current_value'] ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('WS-I Compliance'); ?>:</label></td>
                            <td class="value"><?php echo $checker->renderBooleanField($checker->getSystem()->getMagentoApi()->getComplianceWsi()) ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('WSDL Cache'); ?>:</label></td>
                            <td class="value"><?php echo $checker->renderBooleanField($checker->getSystem()->getMagentoApi()->getWsdlCacheEnabled()) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <span id="php-section"></span>
        <div class="entry-edit">
            <div class="entry-edit-head collapseable">
                <a href="#" class="section-toggler-iceimport">PHP Info</a>
            </div>

            <div class="fieldset iceimport-hidden">
                <div class="hor-scroll">
                    <table class="form-list" cellspacing="0" cellpadding="0">
                        <tr>
                            <td class="label"><label><?php print $helper->__('Version'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getPhp()->getVersion() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Server API'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getPhp()->getServerApi() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Memory Limit'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getPhp()->getMemoryLimit() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Max. Execution Time'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getPhp()->getMaxExecutionTime() ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <span id="mysql-section"></span>
        <div class="entry-edit">
            <div class="entry-edit-head collapseable">
                <a href="#" class="section-toggler-iceimport">MySQL Info</a>
            </div>

            <div class="fieldset iceimport-hidden">
                <div class="hor-scroll">
                    <table class="form-list" cellspacing="0" cellpadding="0">
                        <thead>
                        <tr>
                            <th class="label col1"><?php print $helper->__('Requirement'); ?></th>
                            <th class="value col2"><?php print $helper->__('Current Value'); ?></th>
                            <th class="value col3"><?php print $helper->__('Recommended Value'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Version'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getMysql()->getVersion() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Server API'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getMysql()->getServerApi() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Database Name'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getMysql()->getDatabaseName() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Database Tables'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getMysql()->getDatabaseTables() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Database Table Prefix'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getMysql()->getTablePrefix() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Connection Timeout'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getMysql()->getConnectionTimeout() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Wait Timeout'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getMysql()->getWaitTimeout() ?></td>
                        </tr>
                        <tr>
                            <?php $thread_stack = $checker->getSystem()->getMysql()->getThreadStack(); ?>
                            <td class="label col1">
                                <label><?php print $thread_stack['label']; ?><?php print $checker->renderAdvice($thread_stack); ?>
                                    :</label></td>
                            <td class="value col2 <?php echo $checker->renderRequirementValue($thread_stack['result']) ?>"><?php echo $thread_stack['current_value'] ?></td>
                            <td class="value col3"><?php echo $thread_stack['recommended_value'] ?></td>
                        </tr>
                        <tr>
                            <?php $max_allowed_packet = $checker->getSystem()->getMysql()->getMaxAllowedPacket(); ?>
                            <td class="label col1">
                                <label><?php print $max_allowed_packet['label']; ?><?php print $checker->renderAdvice($max_allowed_packet); ?>
                                    :</label>
                            </td>
                            <td class="value col2 <?php echo $checker->renderRequirementValue($max_allowed_packet['result']) ?>">
                                <?php echo $max_allowed_packet['current_value'] ?>
                            </td>
                            <td class="value col3"><?php echo $max_allowed_packet['recommended_value'] ?></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <span id="mysql_conf-section"></span>
        <div class="entry-edit">
            <div class="entry-edit-head collapseable">
                <a href="#" class="section-toggler-iceimport"><?php print $helper->__('MySQL Configuration'); ?></a>
            </div>

            <div class="fieldset iceimport-hidden">
                <div class="hor-scroll">
                    <table class="form-list" cellspacing="0" cellpadding="0">
                        <?php
                        $mysql_vars = $checker->getSystem()->getMysqlVars()->getData();
                        foreach ($mysql_vars as $mysql_var_key => $mysql_var_value) {
                            print '<tr>';
                            print '<td><strong>' . $mysql_var_key . ':</strong></td>';
                            print '<td class="value">' . $mysql_var_value . '</td>';
                            print '</tr>';
                        }
                        ?>
                    </table>
                </div>
            </div>
        </div>

        <span id="server-section"></span>
        <div class="entry-edit">
            <div class="entry-edit-head collapseable">
                <a href="#" class="section-toggler-iceimport"><?php print $helper->__('Server Info'); ?></a>
            </div>

            <div class="fieldset iceimport-hidden">
                <div class="hor-scroll">
                    <table class="form-list" cellspacing="0" cellpadding="0">
                        <tr>
                            <td class="label"><label><?php print $helper->__('Info'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getServer()->getInfo() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Domain'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getServer()->getDomain() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Server IP'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getServer()->getIp() ?></td>
                        </tr>
                        <tr>
                            <td class="label"><label><?php print $helper->__('Server Directory'); ?>:</label></td>
                            <td class="value"><?php echo $checker->getSystem()->getServer()->getDir() ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <?php
        $iceimport_log = $DB_checker->getLogByType('info', 'neq', 500);
        ?>
        <span id="iceimport_log-section"></span>
        <div class="entry-edit">
            <div class="entry-edit-head collapseable">
                <a href="#" class="section-toggler-iceimport">Iceimport Log</a>
            </div>

            <div class="fieldset iceimport-hidden">
                <div class="hor-scroll">
                    <table class="form-list" cellspacing="0" cellpadding="0">
                        <?php
                        if (!empty($iceimport_log) && count($iceimport_log) > 0) {
                            print '<thead>';
                            print '<tr>';
                            print "<th class=\"label col1\">{$helper->__('Time')}</th>";
                            print "<th class=\"value col2\">{$helper->__('Value')}</th>";
                            print '</tr>';
                            print '</thead>';
                            print '<tbody>';
                            foreach ($iceimport_log as $iceimport_log_item) {
                                print '<tr>';
                                print "<td class=\"label\"><label>{$iceimport_log_item['timecol']}</label></td>";
                                print "<td class=\"value full-width\"><label>{$iceimport_log_item['log_value']}</label></td>";
                                print '</tr>';
                            }
                            print '</tbody>';
                        } else {
                            print '<tbody>';
                            print '<tr>';
                            print "<td class=\"label\"><label>{$helper->__('Still empty')}</label></td>";
                            print '</tr>';
                            print '</tbody>';
                        }
                        ?>
                    </table>
                </div>
            </div>
        </div>
        <style>
            .requirement-passed {
                color: green;
            }

            .requirement-failed {
                color: red;
            }
        </style>
        <?php
        $system_check_content = ob_get_contents();
        ob_end_clean();

        $reset_button_html = $helper->getButtonHtml(array(
            'id' => 'iceimport_check_system_refresh',
            'element_name' => 'iceimport_check_system_refresh',
            'title' => Mage::helper('catalog')->__('Refresh'),
            'type' => 'reset',
            'class' => 'save',
            'label' => Mage::helper('catalog')->__('Refresh'),
            'OnClick' => 'refreshIceimportSystemCheck(\'' . base64_encode(Mage::helper("adminhtml")->getUrl("adminhtml/iceimport/system/")) . '\');'
        ));

        $jsonData = json_encode(array('structure' => $system_check_content, 'refresh_btn' => $reset_button_html));
        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody($jsonData);
    }

    public function phpinfoAction()
    {
        phpinfo(-1);
    }

    public function reportAction()
    {
        header("Content-Type: text/plain; charset=utf-8");
        $report_filename = 'iceimport-report' . (string)Mage::getConfig()->getNode()->modules->ICEshop_Iceimport->version . '.txt';
        header("Content-disposition: attachment; filename={$report_filename}");
        header("Content-Transfer-Encoding: binary");
        header("Pragma: no-cache");
        header("Expires: 0");

        //TODO add report content
        $checker = Mage::helper('iceimport/system_systemcheck')->init();
        $helper = Mage::helper('iceimport');

        //========================================
        //Problems Digest
        //========================================
        $problems_digest = $checker->getExtensionProblemsDigest();
        if ($problems_digest->getCount() != 0) {
            $problems = $problems_digest->getProblems();
            print str_pad('', 100, '=') . "\n";
            print 'Problems Digest' . "\n";
            print str_pad('', 100, '=') . "\n";
            foreach ($problems as $problem_name => $problem_value) {
                print $problem_name . "\n";
                print_r($problem_value);
            }
            print str_pad('', 100, '=') . "\n";
            print "\n";
        }
        //========================================

        //========================================
        //Check module
        //========================================
        $check_module = $checker->getModulesCollection('ICEshop_Iceimport');
        $check_module = $check_module->getLastItem()->getData();
        print str_pad('', 100, '=') . "\n";
        print 'Extension Diagnostic Info' . "\n";
        print str_pad('', 100, '=') . "\n";
        print str_pad('Name', 50) . ':' . str_pad('', 5);
        print $check_module['name'] . "\n";

        print str_pad('Version', 50) . ':' . str_pad('', 5);
        print $check_module['version'] . "\n";

        print str_pad('Code Pool', 50) . ':' . str_pad('', 5);
        print $check_module['code_pool'] . "\n";

        print str_pad('Path', 50) . ':' . str_pad('', 5);
        print $check_module['path'] . "\n";

        print str_pad('Path Exists', 50) . ':' . str_pad('', 5);
        print $checker->renderBooleanField($check_module['path_exists']) . "\n";

        print str_pad('Config Exists', 50) . ':' . str_pad('', 5);
        print $checker->renderBooleanField($check_module['config_exists']) . "\n";

        print str_pad('Dependencies', 50) . ':' . str_pad('', 5);
        print $check_module['dependencies'] . "\n";
        print str_pad('', 100, '=') . "\n";
        print "\n";
        //========================================


        //========================================
        //Check rewrites
        //========================================
        $check_rewrites = $checker->getRewriteCollection('ICEshop_Iceimport');
        print str_pad('', 100, '=') . "\n";
        print 'Extension Rewrites Status' . "\n";
        print str_pad('', 100, '=') . "\n";
        foreach ($check_rewrites as $rewrite) {
            print str_pad('Path', 50) . ':' . str_pad('', 5);
            print $rewrite['path'] . "\n";

            print str_pad('Rewrite Class', 50) . ':' . str_pad('', 5);
            print $rewrite['rewrite_class'] . "\n";

            print str_pad('Active Class', 50) . ':' . str_pad('', 5);
            print $rewrite['active_class'] . "\n";

            print str_pad('Status', 50) . ':' . str_pad('', 5);
            print $checker->renderStatusField($rewrite['status']) . "\n";
        }
        print str_pad('', 100, '=') . "\n";
        print "\n";
        //========================================

        //========================================
        //System Requirements
        //========================================
        $requirements = $checker->getSystem()->getRequirements()->getData();
        print str_pad('', 100, '=') . "\n";
        print 'System Requirements' . "\n";
        print str_pad('', 100, '=') . "\n";
        foreach ($requirements as $requirement) {
            print str_pad($requirement['label'], 50) . ':' . str_pad('', 5);
            print str_pad($requirement['recommended_value'], 30);
            print $requirement['current_value'] . "\n";
        }
        print str_pad('', 100, '=') . "\n";
        print "\n";
        //========================================

        //========================================
        //Magento Info
        //========================================
        print str_pad('', 100, '=') . "\n";
        print 'Magento Info' . "\n";
        print str_pad('', 100, '=') . "\n";
        print str_pad('Edition', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getMagento()->getEdition() . "\n";

        print str_pad('Version', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getMagento()->getVersion() . "\n";

        print str_pad('Developer Mode', 50) . ':' . str_pad('', 5);
        print $checker->renderBooleanField($checker->getSystem()->getMagento()->getDeveloperMode()) . "\n";

        print str_pad('Add Secret Key to URLs', 50) . ':' . str_pad('', 5);
        print print $checker->renderBooleanField($checker->getSystem()->getMagento()->getSecretKey()) . "\n";

        print str_pad('Use Flat Catalog Category', 50) . ':' . str_pad('', 5);
        print $checker->renderBooleanField($checker->getSystem()->getMagento()->getFlatCatalogCategory()) . "\n";

        print str_pad('Use Flat Catalog Product', 50) . ':' . str_pad('', 5);
        print print $checker->renderBooleanField($checker->getSystem()->getMagento()->getFlatCatalogProduct()) . "\n";
        print str_pad('', 100, '=') . "\n";
        print "\n";
        //========================================

        //========================================
        //Magento Core API Info
        //========================================
        print str_pad('', 100, '=') . "\n";
        print 'Magento Core API Info' . "\n";
        print str_pad('', 100, '=') . "\n";
        print str_pad('Default Response Charset', 50) . ':' . str_pad('', 5);
        print print $checker->getSystem()->getMagentoApi()->getCharset() . "\n";

        print str_pad('Client Session Timeout (sec.)', 50) . ':' . str_pad('', 5);
        $magento_api_session_timeout = $checker->getSystem()->getMagentoApi()->getSessionTimeout();
        print $magento_api_session_timeout['current_value'] . "\n";

        print str_pad('WS-I Compliance', 50) . ':' . str_pad('', 5);
        print $checker->renderBooleanField($checker->getSystem()->getMagentoApi()->getComplianceWsi()) . "\n";

        print str_pad('WSDL Cache', 50) . ':' . str_pad('', 5);
        print $checker->renderBooleanField($checker->getSystem()->getMagentoApi()->getWsdlCacheEnabled()) . "\n";
        print str_pad('', 100, '=') . "\n";
        print "\n";
        //========================================

        //========================================
        //PHP Info
        //========================================
        print str_pad('', 100, '=') . "\n";
        print 'PHP Info' . "\n";
        print str_pad('', 100, '=') . "\n";
        print str_pad('Version', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getPhp()->getVersion() . "\n";

        print str_pad('Server API', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getPhp()->getServerApi() . "\n";

        print str_pad('Memory Limit', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getPhp()->getMemoryLimit() . "\n";

        print str_pad('Max. Execution Time', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getPhp()->getMaxExecutionTime() . "\n";
        print str_pad('', 100, '=') . "\n";
        print "\n";
        //========================================

        //========================================
        //MySQL Info
        //========================================
        print str_pad('', 100, '=') . "\n";
        print 'MySQL Info' . "\n";
        print str_pad('', 100, '=') . "\n";
        print str_pad('Version', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getMysql()->getVersion() . "\n";

        print str_pad('Server API', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getMysql()->getServerApi() . "\n";

        print str_pad('Database Name', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getMysql()->getDatabaseName() . "\n";

        print str_pad('Database Tables', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getMysql()->getDatabaseTables() . "\n";

        print str_pad('Database Table Prefix', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getMysql()->getTablePrefix() . "\n";

        print str_pad('Connection Timeout', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getMysql()->getConnectionTimeout() . "\n";

        print str_pad('Wait Timeout', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getMysql()->getWaitTimeout() . "\n";

        print str_pad('Thread stack', 50) . ':' . str_pad('', 5);
        $thread_stack = $checker->getSystem()->getMysql()->getThreadStack();
        print $thread_stack['current_value'] . "\n";

        print str_pad('Max Allowed Packet', 50) . ':' . str_pad('', 5);
        $max_allowed_packet = $checker->getSystem()->getMysql()->getMaxAllowedPacket();
        print $max_allowed_packet['current_value'] . "\n";
        print str_pad('', 100, '=') . "\n";
        print "\n";
        //========================================

        //========================================
        //Server Info
        //========================================
        print str_pad('', 100, '=') . "\n";
        print 'Server Info' . "\n";
        print str_pad('', 100, '=') . "\n";
        print str_pad('Info', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getServer()->getInfo() . "\n";

        print str_pad('Domain', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getServer()->getDomain() . "\n";

        print str_pad('Server IP', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getServer()->getIp() . "\n";

        print str_pad('Server Directory', 50) . ':' . str_pad('', 5);
        print $checker->getSystem()->getServer()->getDir() . "\n";
        print str_pad('', 100, '=') . "\n";
        //========================================


        //========================================
        //phpinfo() full overview
        //========================================
        $formatter = Mage::helper('iceimport/format');
        ob_start();
        phpinfo();
        $phpinfo = ob_get_contents();
        ob_end_clean();

        try {
            print str_pad('', 100, '=') . "\n";
            print 'phpinfo() full overview' . "\n";
            print str_pad('', 100, '=') . "\n";
            print $formatter->convert_html_to_text($phpinfo) . "\n";
            print str_pad('', 100, '=') . "\n";
            print "\n";
        } catch (Exception $e) {
        }
        //========================================


        //========================================
        //MySQL Configuration
        //========================================
        print str_pad('', 100, '=') . "\n";
        print 'MySQL Vars' . "\n";
        print str_pad('', 100, '=') . "\n";
        $mysql_vars = $checker->getSystem()->getMysqlVars()->getData();
        foreach ($mysql_vars as $mysql_var_key => $mysql_var_value) {
            print str_pad($mysql_var_key, 50) . ':' . str_pad('', 5);
            print $mysql_var_value . "\n";
        }
        print str_pad('', 100, '=') . "\n";
        //========================================
    }
}