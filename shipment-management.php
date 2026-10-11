<?php
/**
 * Plugin Name: Shipment Management
 * Description: Standalone shipment tracking with admin management, agent dashboard, timeline, email notifications and CSV import/export. No WooCommerce required.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: shipment-management
 */
if (!defined('ABSPATH')) exit;

final class SM_Shipment_Management {
    const CAP = 'manage_shipments';
    private $shipments;
    private $events;
    private $statuses = array(
        'received' => array('en'=>'Shipment received','ar'=>'تم استلام الشحنة'),
        'processing' => array('en'=>'Processing','ar'=>'جاري تجهيز الشحنة'),
        'in_transit' => array('en'=>'In transit','ar'=>'الشحنة في الطريق'),
        'at_facility' => array('en'=>'Arrived at facility','ar'=>'وصلت إلى مركز التوزيع'),
        'out_for_delivery' => array('en'=>'Out for delivery','ar'=>'خرجت للتوصيل'),
        'delivered' => array('en'=>'Delivered','ar'=>'تم التسليم'),
        'exception' => array('en'=>'Delivery exception','ar'=>'تعذر إتمام التوصيل'),
        'returned' => array('en'=>'Returned','ar'=>'تم إرجاع الشحنة'),
    );

    public function __construct() {
        global $wpdb;
        $this->shipments = $wpdb->prefix . 'sm_shipments';
        $this->events = $wpdb->prefix . 'sm_shipment_events';
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('init', array($this, 'shortcodes'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_post_sm_save_shipment', array($this, 'save_shipment'));
        add_action('admin_post_sm_import_csv', array($this, 'import_csv'));
        add_action('admin_post_sm_export_csv', array($this, 'export_csv'));
        add_action('admin_post_sm_agent_update', array($this, 'agent_update'));
    }

    public function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->shipments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tracking_code varchar(64) NOT NULL,
            customer_name varchar(190) NOT NULL DEFAULT '',
            customer_email varchar(190) NOT NULL DEFAULT '',
            customer_phone varchar(40) NOT NULL DEFAULT '',
            destination varchar(190) NOT NULL DEFAULT '',
            current_status varchar(40) NOT NULL DEFAULT 'received',
            assigned_agent bigint(20) unsigned NOT NULL DEFAULT 0,
            estimated_delivery date DEFAULT NULL,
            public_note text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tracking_code (tracking_code),
            KEY current_status (current_status),
            KEY assigned_agent (assigned_agent)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            shipment_id bigint(20) unsigned NOT NULL,
            status varchar(40) NOT NULL,
            location varchar(190) NOT NULL DEFAULT '',
            note text DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY shipment_id (shipment_id),
            KEY created_at (created_at)
        ) $charset;");
        add_role('shipment_agent', 'Shipment Agent', array('read'=>true));
        $admin = get_role('administrator');
        if ($admin) $admin->add_cap(self::CAP);
    }

    private function can_manage() { return current_user_can(self::CAP) || current_user_can('manage_options'); }
    private function label($status, $lang='en') {
        return isset($this->statuses[$status][$lang]) ? $this->statuses[$status][$lang] : $status;
    }
    public function shortcodes() {
        add_shortcode('shipment_tracking', array($this, 'tracking_shortcode'));
        add_shortcode('shipment_agent_dashboard', array($this, 'agent_shortcode'));
    }

    public function tracking_shortcode() {
        global $wpdb;
        $lang = (isset($_REQUEST['lang']) && sanitize_key(wp_unslash($_REQUEST['lang'])) === 'ar') ? 'ar' : 'en';
        $rtl = $lang === 'ar';
        $code = isset($_POST['sm_tracking_code']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['sm_tracking_code']))) : '';
        $shipment = $code ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->shipments} WHERE tracking_code=%s", $code)) : null;
        ob_start(); ?>
        <div class="sm-tracking" dir="<?php echo $rtl ? 'rtl' : 'ltr'; ?>">
        <style>
        .sm-tracking{max-width:760px;margin:24px auto;color:#172033;font-family:inherit}.sm-card{border:1px solid #e2e8f0;border-radius:16px;padding:24px;background:#fff;box-shadow:0 8px 28px #0f172a0a}.sm-tracking h2{font-size:30px;margin:0 0 8px}.sm-muted{color:#64748b}.sm-form{display:flex;gap:10px;margin:20px 0;flex-wrap:wrap}.sm-form input{flex:1;min-width:180px;padding:13px;border:1px solid #cbd5e1;border-radius:9px;font:inherit}.sm-form button,.sm-btn{background:#155eef;color:white;border:0;border-radius:9px;padding:12px 18px;cursor:pointer;font:inherit}.sm-pill{display:inline-block;background:#eaf2ff;color:#1549a6;padding:7px 12px;border-radius:999px;font-weight:600}.sm-timeline{list-style:none;padding:0;margin-top:20px}.sm-timeline li{border-left:2px solid #dbeafe;margin-left:7px;padding:0 0 22px 25px;position:relative}.sm-timeline li:before{content:"";position:absolute;left:-7px;top:3px;width:12px;height:12px;border-radius:50%;background:#2563eb}[dir=rtl] .sm-timeline li{border-left:0;border-right:2px solid #dbeafe;margin:0 7px 0 0;padding:0 25px 22px 0}[dir=rtl] .sm-timeline li:before{left:auto;right:-7px}.sm-error{background:#fff1f2;color:#9f1239;padding:12px;border-radius:8px}
        @media(max-width:520px){.sm-card{padding:16px}.sm-form button{width:100%}}
        </style>
        <div class="sm-card">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"><div><h2><?php echo esc_html($rtl?'تتبع شحنتك':'Track your shipment'); ?></h2><p class="sm-muted"><?php echo esc_html($rtl?'أدخل رقم التتبع لمعرفة آخر تحديث.':'Enter your tracking number to see the latest updates.'); ?></p></div><div><a href="<?php echo esc_url(add_query_arg('lang','en')); ?>">EN</a> · <a href="<?php echo esc_url(add_query_arg('lang','ar')); ?>">عربي</a></div></div>
        <form class="sm-form" method="post"><input required maxlength="64" name="sm_tracking_code" value="<?php echo esc_attr($code); ?>" placeholder="<?php echo esc_attr($rtl?'رقم التتبع':'Tracking number'); ?>"><input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>"><button><?php echo esc_html($rtl?'تتبع الشحنة':'Track shipment'); ?></button></form>
        <?php if ($code && !$shipment): ?><p class="sm-error"><?php echo esc_html($rtl?'لم يتم العثور على شحنة بهذا الرقم.':'No shipment found for this tracking number.'); ?></p>
        <?php elseif ($shipment): $events=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->events} WHERE shipment_id=%d ORDER BY created_at DESC,id DESC",$shipment->id)); ?>
        <p><strong><?php echo esc_html($rtl?'رقم التتبع:':'Tracking:'); ?></strong> <?php echo esc_html($shipment->tracking_code); ?></p><p><span class="sm-pill"><?php echo esc_html($this->label($shipment->current_status,$lang)); ?></span></p>
        <?php if ($shipment->destination): ?><p><strong><?php echo esc_html($rtl?'الوجهة:':'Destination:'); ?></strong> <?php echo esc_html($shipment->destination); ?></p><?php endif; ?>
        <?php if ($shipment->estimated_delivery): ?><p><strong><?php echo esc_html($rtl?'التوصيل المتوقع:':'Estimated delivery:'); ?></strong> <?php echo esc_html($shipment->estimated_delivery); ?></p><?php endif; ?>
        <?php if ($shipment->public_note): ?><p><?php echo esc_html($shipment->public_note); ?></p><?php endif; ?>
        <h3><?php echo esc_html($rtl?'سجل التحديثات':'Shipment timeline'); ?></h3><ol class="sm-timeline"><?php foreach($events as $e): ?><li><strong><?php echo esc_html($this->label($e->status,$lang)); ?></strong><div class="sm-muted"><?php echo esc_html(mysql2date(get_option('date_format').' '.get_option('time_format'),$e->created_at)); ?></div><?php if($e->location): ?><div><?php echo esc_html($e->location); ?></div><?php endif; ?><?php if($e->note): ?><div><?php echo esc_html($e->note); ?></div><?php endif; ?></li><?php endforeach; ?></ol>
        <?php endif; ?></div></div><?php
        return ob_get_clean();
    }

    public function admin_menu() {
        add_menu_page('Shipments','Shipments',self::CAP,'sm-shipments',array($this,'admin_page'),'dashicons-location-alt',26);
        add_submenu_page('sm-shipments','Add Shipment','Add Shipment',self::CAP,'sm-add-shipment',array($this,'add_page'));
        add_submenu_page('sm-shipments','CSV Import / Export','CSV Import / Export',self::CAP,'sm-csv',array($this,'csv_page'));
    }

    public function admin_page() {
        if (!$this->can_manage()) wp_die('Permission denied.');
        global $wpdb;
        $q=isset($_GET['s'])?sanitize_text_field(wp_unslash($_GET['s'])):'';
        if($q){$like='%'.$wpdb->esc_like($q).'%';$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->shipments} WHERE tracking_code LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s ORDER BY updated_at DESC LIMIT 200",$like,$like,$like));}
        else $rows=$wpdb->get_results("SELECT * FROM {$this->shipments} ORDER BY updated_at DESC LIMIT 200");
        echo '<div class="wrap"><h1>Shipment Management</h1><p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=sm-add-shipment')).'">Add shipment</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=sm-csv')).'">CSV Import / Export</a></p><form method="get"><input type="hidden" name="page" value="sm-shipments"><input type="search" name="s" value="'.esc_attr($q).'" placeholder="Tracking, customer, email"> <button class="button">Search</button></form><table class="widefat striped" style="margin-top:15px"><thead><tr><th>Tracking</th><th>Customer</th><th>Status</th><th>Agent</th><th>Updated</th><th></th></tr></thead><tbody>';
        foreach($rows as $r){$a=$r->assigned_agent?get_userdata($r->assigned_agent):false;echo '<tr><td>'.esc_html($r->tracking_code).'</td><td>'.esc_html($r->customer_name).'<br>'.esc_html($r->customer_email).'</td><td>'.esc_html($this->label($r->current_status)).'</td><td>'.esc_html($a?$a->display_name:'—').'</td><td>'.esc_html($r->updated_at).'</td><td><a class="button button-small" href="'.esc_url(admin_url('admin.php?page=sm-add-shipment&edit='.absint($r->id))).'">Edit</a></td></tr>';}
        if(!$rows) echo '<tr><td colspan="6">No shipments found.</td></tr>';
        echo '</tbody></table></div>';
    }

    public function add_page() {
        if(!$this->can_manage()) wp_die('Permission denied.');
        global $wpdb;
        $id=isset($_GET['edit'])?absint($_GET['edit']):0;
        $r=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->shipments} WHERE id=%d",$id)):null;
        $agents=get_users(array('role'=>'shipment_agent','fields'=>array('ID','display_name')));
        echo '<div class="wrap"><h1>'.($r?'Edit shipment':'Add shipment').'</h1><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('sm_save_shipment');echo '<input type="hidden" name="action" value="sm_save_shipment"><input type="hidden" name="shipment_id" value="'.absint($id).'"><table class="form-table">';
        $fields=array('tracking_code'=>'Tracking number','customer_name'=>'Customer name','customer_email'=>'Customer email','customer_phone'=>'Customer phone (international format)','destination'=>'Destination');
        foreach($fields as $k=>$label){$val=$r?$r->$k:($k==='tracking_code'?'TRK-'.gmdate('Y').'-'.strtoupper(wp_generate_password(6,false,false)):'');$type=$k==='customer_email'?'email':'text';echo '<tr><th><label for="'.esc_attr($k).'">'.esc_html($label).'</label></th><td><input '.($k==='tracking_code'?'required':'').' class="regular-text" type="'.esc_attr($type).'" id="'.esc_attr($k).'" name="'.esc_attr($k).'" value="'.esc_attr($val).'"></td></tr>';}
        echo '<tr><th>Status</th><td><select name="current_status">';foreach($this->statuses as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected($r?$r->current_status:'received',$k,false).'>'.esc_html($v['en'].' / '.$v['ar']).'</option>';echo '</select></td></tr><tr><th>Assigned agent</th><td><select name="assigned_agent"><option value="0">Unassigned</option>';foreach($agents as $a)echo '<option value="'.absint($a->ID).'" '.selected($r?$r->assigned_agent:0,$a->ID,false).'>'.esc_html($a->display_name).'</option>';echo '</select> <a href="'.esc_url(admin_url('user-new.php')).'">Create a user and select Shipment Agent role</a></td></tr>';
        echo '<tr><th>Estimated delivery</th><td><input type="date" name="estimated_delivery" value="'.esc_attr($r?$r->estimated_delivery:'').'"></td></tr><tr><th>Update location</th><td><input class="regular-text" name="location"></td></tr><tr><th>Update note</th><td><textarea class="large-text" name="event_note" rows="3"></textarea></td></tr><tr><th>Public note</th><td><textarea class="large-text" name="public_note" rows="2">'.esc_textarea($r?$r->public_note:'').'</textarea></td></tr></table>';
        submit_button($r?'Save shipment':'Create shipment');echo '</form></div>';
    }

    public function save_shipment() {
        if(!$this->can_manage()) wp_die('Permission denied.');
        check_admin_referer('sm_save_shipment');
        global $wpdb;
        $id=absint($_POST['shipment_id']??0);$code=strtoupper(sanitize_text_field(wp_unslash($_POST['tracking_code']??'')));$status=sanitize_key(wp_unslash($_POST['current_status']??'received'));
        if(!$code||!isset($this->statuses[$status])) wp_die('Invalid tracking number or status.');
        $data=array('tracking_code'=>$code,'customer_name'=>sanitize_text_field(wp_unslash($_POST['customer_name']??'')),'customer_email'=>sanitize_email(wp_unslash($_POST['customer_email']??'')),'customer_phone'=>sanitize_text_field(wp_unslash($_POST['customer_phone']??'')),'destination'=>sanitize_text_field(wp_unslash($_POST['destination']??'')),'current_status'=>$status,'assigned_agent'=>absint($_POST['assigned_agent']??0),'estimated_delivery'=>sanitize_text_field(wp_unslash($_POST['estimated_delivery']??''))?:null,'public_note'=>sanitize_textarea_field(wp_unslash($_POST['public_note']??'')),'updated_at'=>current_time('mysql'));
        $old=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->shipments} WHERE id=%d",$id)):null;
        if(!$old)$data['created_at']=current_time('mysql');
        $ok=$id?$wpdb->update($this->shipments,$data,array('id'=>$id)):$wpdb->insert($this->shipments,$data);
        if($ok===false) wp_die('Unable to save. Tracking number may already exist.');
        $sid=$id?:$wpdb->insert_id;
        if(!$old||$old->current_status!==$status||!empty($_POST['event_note'])||!empty($_POST['location']))$this->add_event($sid,$status,sanitize_text_field(wp_unslash($_POST['location']??'')),sanitize_textarea_field(wp_unslash($_POST['event_note']??'')));
        if(!$old||$old->current_status!==$status)$this->notify($data,$code,$status);
        wp_safe_redirect(admin_url('admin.php?page=sm-add-shipment&edit='.absint($sid).'&saved=1'));exit;
    }

    private function add_event($id,$status,$location,$note) {
        global $wpdb;
        $wpdb->insert($this->events,array('shipment_id'=>absint($id),'status'=>$status,'location'=>$location,'note'=>$note,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')));
    }

    private function notify($shipment,$code,$status) {
        if(empty($shipment['customer_email'])||!is_email($shipment['customer_email']))return;
        wp_mail($shipment['customer_email'],'Shipment update: '.$code,"Your shipment status has been updated.\nTracking number: {$code}\nStatus: ".$this->label($status)."\nTrack: ".home_url('/'));
    }

    public function agent_shortcode() {
        if(!is_user_logged_in())return '<p>Please log in to view your assigned shipments.</p>';
        $user=wp_get_current_user();
        if(!in_array('shipment_agent',(array)$user->roles,true)&&!$this->can_manage())return '<p>You do not have permission to view this page.</p>';
        global $wpdb;
        $is_manager=$this->can_manage();
        $rows=$is_manager?$wpdb->get_results("SELECT * FROM {$this->shipments} ORDER BY updated_at DESC LIMIT 200"):$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->shipments} WHERE assigned_agent=%d ORDER BY updated_at DESC LIMIT 200",get_current_user_id()));
        $msg=isset($_GET['sm_updated'])?'Shipment status updated.':'';
        ob_start(); ?>
        <div class="sm-agent-dashboard">
        <style>.sm-agent-dashboard{max-width:1100px;margin:24px auto;overflow-x:auto}.sm-agent-dashboard table{width:100%;border-collapse:collapse}.sm-agent-dashboard th,.sm-agent-dashboard td{padding:12px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.sm-agent-dashboard input,.sm-agent-dashboard select,.sm-agent-dashboard textarea{max-width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px}.sm-agent-dashboard button{padding:9px 12px;background:#155eef;color:#fff;border:0;border-radius:7px;cursor:pointer}.sm-agent-dashboard .sm-muted{color:#64748b;font-size:13px}</style>
        <h2>My shipments</h2><?php if($msg): ?><p role="status"><?php echo esc_html($msg); ?></p><?php endif; ?>
        <table><thead><tr><th>Tracking / destination</th><th>Current status</th><th>Update shipment</th></tr></thead><tbody>
        <?php if(!$rows): ?><tr><td colspan="3">No assigned shipments.</td></tr><?php endif; ?>
        <?php foreach($rows as $r): ?><tr><td><strong><?php echo esc_html($r->tracking_code); ?></strong><div><?php echo esc_html($r->destination); ?></div><div class="sm-muted"><?php echo esc_html($r->customer_name); ?></div></td><td><?php echo esc_html($this->label($r->current_status)); ?></td><td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('sm_agent_update_'.$r->id); ?><input type="hidden" name="action" value="sm_agent_update"><input type="hidden" name="shipment_id" value="<?php echo absint($r->id); ?>"><select name="new_status" required><?php foreach($this->statuses as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($r->current_status,$k); ?>><?php echo esc_html($v['en'].' / '.$v['ar']); ?></option><?php endforeach; ?></select><p><input name="location" placeholder="Current location"></p><p><textarea name="note" rows="2" placeholder="Update note (visible to customer)"></textarea></p><button type="submit">Save update</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div><?php
        return ob_get_clean();
    }

    public function agent_update() {
        if(!is_user_logged_in()) wp_die('Please log in.');
        $id=absint($_POST['shipment_id']??0);
        check_admin_referer('sm_agent_update_'.$id);
        global $wpdb;
        $shipment=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->shipments} WHERE id=%d",$id));
        if(!$shipment) wp_die('Shipment not found.');
        $user=wp_get_current_user();
        $manager=$this->can_manage();
        if(!$manager&&(!in_array('shipment_agent',(array)$user->roles,true)||(int)$shipment->assigned_agent!==(int)$user->ID))wp_die('You are not allowed to update this shipment.');
        $status=sanitize_key(wp_unslash($_POST['new_status']??''));
        if(!isset($this->statuses[$status]))wp_die('Invalid shipment status.');
        $location=sanitize_text_field(wp_unslash($_POST['location']??''));$note=sanitize_textarea_field(wp_unslash($_POST['note']??''));
        $wpdb->update($this->shipments,array('current_status'=>$status,'updated_at'=>current_time('mysql')),array('id'=>$id));
        $this->add_event($id,$status,$location,$note);
        $this->notify(array('customer_email'=>$shipment->customer_email),$shipment->tracking_code,$status);
        $back=wp_get_referer();if(!$back)$back=home_url('/');
        wp_safe_redirect(add_query_arg('sm_updated','1',$back));exit;
    }

    public function csv_page() {
        if(!$this->can_manage())wp_die('Permission denied.');
        echo '<div class="wrap"><h1>CSV Import / Export</h1><h2>Export</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('sm_export_csv');echo '<input type="hidden" name="action" value="sm_export_csv">';submit_button('Download CSV','secondary','submit',false);echo '</form><hr><h2>Import</h2><p>Headers: tracking_code, customer_name, customer_email, customer_phone, destination, current_status, estimated_delivery. Valid statuses: received, processing, in_transit, at_facility, out_for_delivery, delivered, exception, returned.</p><form enctype="multipart/form-data" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('sm_import_csv');echo '<input type="hidden" name="action" value="sm_import_csv"><input type="file" name="sm_csv" accept=".csv,text/csv" required>';submit_button('Import CSV','primary','submit',false);echo '</form></div>';
    }

    public function export_csv() {
        if(!$this->can_manage())wp_die('Permission denied.');
        check_admin_referer('sm_export_csv');
        global $wpdb;
        $rows=$wpdb->get_results("SELECT tracking_code,customer_name,customer_email,customer_phone,destination,current_status,assigned_agent,estimated_delivery,public_note,created_at,updated_at FROM {$this->shipments} ORDER BY id",ARRAY_A);
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=shipments-'.gmdate('Y-m-d').'.csv');
        $f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,array('tracking_code','customer_name','customer_email','customer_phone','destination','current_status','assigned_agent','estimated_delivery','public_note','created_at','updated_at'));foreach($rows as $row)fputcsv($f,$row);fclose($f);exit;
    }

    public function import_csv() {
        if(!$this->can_manage())wp_die('Permission denied.');
        check_admin_referer('sm_import_csv');
        if(empty($_FILES['sm_csv']['tmp_name'])||!is_uploaded_file($_FILES['sm_csv']['tmp_name']))wp_die('Upload a CSV file.');
        if((int)($_FILES['sm_csv']['size']??0)>5242880)wp_die('CSV must be 5 MB or smaller.');
        $f=fopen($_FILES['sm_csv']['tmp_name'],'r');if(!$f)wp_die('Could not read CSV.');
        $headers=fgetcsv($f);if(!$headers)wp_die('CSV is empty.');
        $headers=array_map(function($v){return sanitize_key(trim((string)$v));},$headers);
        $required=array('tracking_code','customer_name','customer_email','customer_phone','destination','current_status','estimated_delivery');
        if(array_diff($required,$headers)){fclose($f);wp_die('CSV is missing required headers.');}
        global $wpdb;$count=0;
        while(($line=fgetcsv($f))!==false){if(count($line)!==count($headers))continue;$row=array_combine($headers,$line);$code=strtoupper(sanitize_text_field($row['tracking_code']));$status=sanitize_key($row['current_status']);if(!$code||!isset($this->statuses[$status]))continue;
            $data=array('tracking_code'=>$code,'customer_name'=>sanitize_text_field($row['customer_name']),'customer_email'=>sanitize_email($row['customer_email']),'customer_phone'=>sanitize_text_field($row['customer_phone']),'destination'=>sanitize_text_field($row['destination']),'current_status'=>$status,'estimated_delivery'=>sanitize_text_field($row['estimated_delivery'])?:null,'updated_at'=>current_time('mysql'));
            $old=$wpdb->get_row($wpdb->prepare("SELECT id,current_status FROM {$this->shipments} WHERE tracking_code=%s",$code));
            if($old){$wpdb->update($this->shipments,$data,array('id'=>$old->id));if($old->current_status!==$status){$this->add_event($old->id,$status,'','Status updated by CSV import');$this->notify($data,$code,$status);}}
            else{$data['created_at']=current_time('mysql');$wpdb->insert($this->shipments,$data);$sid=$wpdb->insert_id;$this->add_event($sid,$status,'','Imported from CSV');}
            $count++;
        }
        fclose($f);wp_safe_redirect(add_query_arg(array('page'=>'sm-csv','imported'=>$count),admin_url('admin.php')));exit;
    }
}
new SM_Shipment_Management();
