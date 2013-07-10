<?php
/*
 * LDAP Alias Sync: Syncronize users' identities (name, email, organization, reply-to, bcc, signature)
 * by querying an LDAP server's aliasses.
 *
 * Based on the 'IdentiTeam' Plugin by André Rodier <andre.rodier@gmail.com>
 * Author: Lukas Mika <lukas.mika@web.de>
 * Licence: GPLv3. (See copying)
 */
class ldapAliasSync extends rcube_plugin {
    
    public $task = 'login';

    // Internal variables
    private $initialised;
    private $config;
    private $app;
    private $conn;

    // mail parameters
    private $mail;
    private $domain;
    private $separator;
    private $remove_domain;

    // LDAP parameters
    private $ldap;
    private $server;
    private $bind_dn;
    private $bind_pw;
    private $base_dn;
    private $filter;
    private $attr_mail;
    private $attr_name;
    private $attr_org;
    private $attr_reply;
    private $attr_bcc;
    private $attr_sig;
    private $fields;

    function init() {
        try {
            write_log('ldapAliasSync', 'Initialising');
            
            # Load default config, and merge with users' settings
            $this->load_config('config-default.inc.php');
            $this->load_config('config.inc.php');

            $this->app = rcmail::get_instance();
            $this->config = $this->app->config->get('ldapAliasSync');

            # Load LDAP & mail config at once
            $this->ldap = $this->config['ldap'];
            $this->mail = $this->config['mail'];

            # Load LDAP configs
            $this->server       = $this->ldap['server'];
            $this->bind_dn      = $this->ldap['bind_dn'];
            $this->bind_pw      = $this->ldap['bind_pw'];
            $this->base_dn      = $this->ldap['base_dn'];
            $this->filter       = $this->ldap['filter'];
            $this->attr_mail    = $this->ldap['attr_mail'];
            $this->attr_name    = $this->ldap['attr_name'];
            $this->attr_org     = $this->ldap['attr_org'];
            $this->attr_reply   = $this->ldap['attr_reply'];
            $this->attr_bcc     = $this->ldap['attr_bcc'];
            $this->attr_sig     = $this->ldap['attr_sig'];
            
            $this->fields = array($this->attr_mail, $this->attr_name, $this->attr_org, $this->attr_reply,
                $this->attr_bcc, $this->attr_sig);

            # Load mail configs
            $this->domain        = $this->mail['domain'];
            $this->separator     = $this->mail['dovecot_impersonate_seperator'];
            $this->remove_domain = $this->mail['remove_domain'];

            # LDAP Connection
            $this->conn = ldap_connect($this->server);

            if ( is_resource($this->conn) ) {
                ldap_set_option($this->conn, LDAP_OPT_PROTOCOL_VERSION, 3);

                $bound = ldap_bind($this->conn, $this->bind_dn, $this->bind_pw);

                if ( $bound ) {
                    # register hook
                    $this->add_hook('user2email', array($this, 'user2email'));
                    $this->initialised = true;
                } else {
                    $log = sprintf("Bind to server '%s' failed. Con: (%s), Error: (%s)",
                        $this->server,
                        $this->conn,
                        ldap_errno($this->conn));
                    write_log('ldapAliasSync', $log);
                }
            } else {
                $log = sprintf("Connection to the server failed: (Error=%s)", ldap_errno($this->conn));
                write_log('ldapAliasSync', $log);
            }
        } catch ( Exception $exc ) {
            write_log('ldapAliasSync', 'Fail to initialise: '.$exc->getMessage());
        }

        if ( $this->initialised )
            write_log('ldapAliasSync', 'Initialised');
    }

    /**
     * user2email
     * 
     * See http://trac.roundcube.net/wiki/Plugin_Hooks
     * Return values:
     * email: E-mail address (or array of arrays with keys: email, name, organization, reply-to, bcc, signature, html_signature) 
     */
    function user2email($args) {
        $login    = $args['user'];      # User login
        $first    = $args['first'];     # True if one entry is expected
        $extended = $args['extended'];  # True if array result (email and identity data) is expected
        $email    = $args['email'];

        # ensure we return valid information
        $args['extended'] = true;
        $args['first']    = false;
        $args['abort']    = false;

        try {
            # if set to true, the domain name is removed before the lookup 
            if ( $remove_domain ) {            
                $login = array_shift(explode('@', $login));
            } else {
                # check if we need to add a domain if not specified in the login name
                if ( !strstr($login, '@') && $domain ) {
                    $login = "$login@$domain" ;
                }
            }

            # Check if dovecot master user is used. Use the same configuration name than
            # dovecot_impersonate plugin for roundcube
            if ( strpos($login, $separator) != false ) {   
                $log = sprintf("Removed dovecot impersonate separator (%s) in the login name", $separator);
                write_log('ldapAliasSync', $log);

                $login = array_shift(explode($separator, $login));
            }   

            # Replace placeholder with login
            $ldap_filter = sprintf($this->filter, $login);
            
            # Search for LDAP data
            $result = ldap_search($this->conn, $this->domain, $ldap_filter, $this->fields);

            if ( $result ) {
                $info = ldap_get_entries($this->conn, $result);

                if ( $info['count'] >= 1 ) {
                    $log = sprintf("Found the user '%s' in the database", $login);
                    write_log('ldapAliasSync', $log);

                    $identities = array();

                    # Collect the identity information
                    foreach ( $result as $ldapID ) {
                        $email        = $ldapID[$attr_mail];
                        $name         = $ldapID[$attr_name];
                        $organisation = $ldapID[$attr_org];
                        $reply        = $ldapID[$attr_reply];
                        $bcc          = $ldapID[$attr_bcc];
                        $signature    = $ldapID[$attr_sig];

                        if ( !strstr($email, '@') && $domain ) $email = $email.'@'.$domain;
                        if ( !$name )         $name         = '';
                        if ( !$organisation ) $organisation = '';
                        if ( !$reply )        $reply        = '';
                        if ( !$bcc )          $bcc          = '';
                        if ( !$signature )    $signature    = '';
                        
                        # If the signature starts with an HTML tag, we mark the signature as HTML
                        if ( preg_match('/^\s*<[a-zA-Z]+/', $signature) ) {
                            $isHtml = 1;
                        } else {
                            $isHtml = 0;
                        }

                        $identity[] = array(
                            'email' => $email,
                            'name' => $name,
                            'organization' => $organisation,
                            'reply-to' => $reply,
                            'bcc' => $bcc,
                            'signature' => $signature,
                            'html_signature' = $isHtml,
                        );
                            
                        array_push($identities[], $identity);
                    }
                    
                    # Return structure for our LDAP identities
                    $args['email'] = $identities;
                    
                    # Check which identities are available in database but nut in LDAP and delete those
                    if (count($identities[]) > 0 && $db_identities[] = $this->app->user->list_identities()) {
                        foreach ($db_identities as $db_identity) {
                            $in_ldap = null;
                            
                            foreach ($identities as $identity) {
                                # email is our only comparison parameter
                                if($db_identity['email'] == $identity['email'] && !$in_ldap) {
                                    $in_ldap = $db_identity['identity_id'];
                                }
                            }
                            
                            # If this identity does not exist in LDAP, delete it from database
                            if (!$in_ldap) {
                                $db_user->delete_identity($in_ldap);
                            }
                        }
                        $log = sprintf("Identities synced for %s", $login);
                        write_log('ldapAliasSync', $log);
                    }
                } else {
                    $log = sprintf("User '%s' not found (pass 2). Filter: %s", $login, $filter);
                    write_log('ldapAliasSync', $log);
                }
            } else {
                $log = sprintf("User '%s' not found (pass 1). Filter: %s", $login, $filter);
                write_log('ldapAliasSync', $log);
            }

            ldap_close($this->conn);
        }
        return $args;
    }
}
?>