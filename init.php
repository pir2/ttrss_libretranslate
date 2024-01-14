<?php
class libretranslate extends Plugin
{

    /* @var PluginHost $host */
    private $host;

    public function about()
    {
        return array(1.0,
            "Translation of feed using LibreTranslate",
            "pir2");
    }

    public function flags()
    {
        return array("needs_curl" => true);
    }

    public function save()
    {
        $this->host->set($this, "libretranslate_API_server", $_POST["libretranslate_API_server"]);
        $this->host->set($this, "libretranslate_target_language", $_POST["libretranslate_target_language"]);

        echo __("API server address saved.");
    }

    public function init($host)
    {
        $this->host = $host;

        if (version_compare(PHP_VERSION, '7.0.0', '<')) {
            user_error("libretranslate requires PHP 7.0", E_USER_WARNING);
            return;
        }

        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
        $host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
        $host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);

        $host->add_filter_action($this, "libretranslate", __("libretranslate"));
    }

    public function get_js()
    {
        return file_get_contents(__DIR__ . "/init.js");
    }

    public function hook_article_button($line)
    {
        return "<i class='material-icons'
			style='cursor : pointer' onclick='Plugins.libretranslate.convert(".$line["id"].")'
			title='".__('Convert via libretranslate')."'>g_translate</i>";
    }


    public function hook_prefs_tab($args)
    {
        if ($args != "prefFeeds") {
            return;
        }

        print "<div dojoType='dijit.layout.AccordionPane' 
			title=\"<i class='material-icons'>extension</i> ".__('libretranslate settings (libretranslate)')."\">";

        if (version_compare(PHP_VERSION, '7.0.0', '<')) {
            print_error("This plugin requires PHP 7.0.");
        } else {
            print_notice("Enable the plugin for specific feeds in the feed editor.");

            print "<form dojoType='dijit.form.Form'>";

            print "<script type='dojo/method' event='onSubmit' args='evt'>
                evt.preventDefault();
                if (this.validate()) {
                xhr.post(\"backend.php\", this.getValues(), (reply) => {
                            Notify.info(reply);
                        })
                }
                </script>";

            print \Controls\pluginhandler_tags($this, "save");

            $libretranslate_API_server = $this->host->get($this, "libretranslate_API_server");
            $libretranslate_target_language = $this->host->get($this, "libretranslate_target_language");
            

            print "<input dojoType='dijit.form.ValidationTextBox' required='1' name='libretranslate_API_server' value='" . $libretranslate_API_server . "'/>";

            print "&nbsp;<label for='libretranslate_API_server'>" . __("LibreTranslate API server address, with HTTP/HTTPS protocol. e.g. https://ip-address:port/translate") . "</label>";

            print "<input dojoType='dijit.form.ValidationTextBox' required='1' name='libretranslate_target_language' value='" . $libretranslate_target_language . "'/>";

            print "&nbsp;<label for='libretranslate_API_server'>" . __("LibreTranslate target language Default en.") . "</label>";
    
    

            print "<p>Read the <a href='https://github.com/pir2/ttrss_libretranslate'>documents</a>.</p>";
            print "<p>Read the <a href='https://www.argosopentech.com/argospm/index/'>Target Language List</a>.</p>";
            print "<button dojoType=\"dijit.form.Button\" type=\"submit\" class=\"alt-primary\">".__('Save')."</button>";

            print "</form>";

            $enabled_feeds = $this->host->get($this, "enabled_feeds");
            if (!is_array($enabled_feeds)) {
                $enabled_feeds = array();
            }

            $enabled_feeds = $this->filter_unknown_feeds($enabled_feeds);
            $this->host->set($this, "enabled_feeds", $enabled_feeds);

            if (count($enabled_feeds) > 0) {
                print "<h3>" . __("Currently enabled for (click to edit):") . "</h3>";

                print "<ul class='panel panel-scrollable list list-unstyled'>";
                foreach ($enabled_feeds as $f) {
                    print "<li>" .
                    "<i class='material-icons'>rss_feed</i> <a href='#'
						onclick='CommonDialogs.editFeed($f)'>".
                    Feeds::_get_title($f) . "</a></li>";

                }
                print "</ul>";
            }
        }

        print "</div>";
    }

    public function hook_prefs_edit_feed($feed_id)
    {
        print "<header>".__("LibreTranslate")."</header>";
        print "<section>";

        $enabled_feeds = $this->host->get($this, "enabled_feeds");
        if (!is_array($enabled_feeds)) {
            $enabled_feeds = array();
        }

        $key = array_search($feed_id, $enabled_feeds);
        $checked = $key !== false ? "checked" : "";

        print "<fieldset>";

        print "<label class='checkbox'><input dojoType='dijit.form.CheckBox' type='checkbox' id='libretranslate_enabled'
				name='libretranslate_enabled' $checked>&nbsp;".__('Enable libretranslate')."</label>";
    
        print "</fieldset>";
    
        print "</section>";
    }

    public function hook_prefs_save_feed($feed_id)
    {
        $enabled_feeds = $this->host->get($this, "enabled_feeds");
        if (!is_array($enabled_feeds)) {
            $enabled_feeds = array();
        }

        $enable = checkbox_to_sql_bool($_POST["libretranslate_enabled"]);
        $key = array_search($feed_id, $enabled_feeds);

        if ($enable) {
            if ($key === false) {
                array_push($enabled_feeds, $feed_id);
            }
        } else {
            if ($key !== false) {
                unset($enabled_feeds[$key]);
            }
        }

        $this->host->set($this, "enabled_feeds", $enabled_feeds);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function hook_article_filter_action($article, $action)
    {
        return $this->process_article($article);
    }
    
    
    public function send_request($content)
    {
        $ch = curl_init();
        $libretranslate_API_server = $this->host->get($this, "libretranslate_API_server");
        $libretranslate_target_language = $this->host->get($this, "libretranslate_target_language");
        $request_headers = array('Content-Type: application/json');
        $request_body = json_encode(array(
                'source' => "auto",    
                'q' => $content, 
                'target' => $libretranslate_target_language,
                'format' => "html"
            )
            );

        curl_setopt($ch, CURLOPT_URL, $libretranslate_API_server);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
        curl_setopt($ch, CURLOPT_ENCODING, "UTF-8");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 400); //timeout in seconds        

        $output = json_decode(curl_exec($ch));
        curl_close($ch);
        return $output;
    }
    
    public function process_article($article)
    {
        
        $title = $this->send_request($article["title"]);
        if ($title->translatedText) {
            $article["title"] = $title->translatedText;
        }

        $content = $this->send_request($article["content"]);
        if ($content->translatedText) {
            $article["content"] = "Language: " . $content->detectedLanguage->language .
                     "\nConfidence: " . $content->detectedLanguage->confidence . 
                     "\n\n" . $content->translatedText;
        }

        return $article;
    }

    public function hook_article_filter($article)
    {
        $enabled_feeds = $this->host->get($this, "enabled_feeds");
        if (!is_array($enabled_feeds)) {
            return $article;
        }

        $key = array_search($article["feed"]["id"], $enabled_feeds);
        if ($key === false) {
            return $article;
        }

        return $this->process_article($article);
    }

    public function api_version()
    {
        return 2;
    }

    private function filter_unknown_feeds($enabled_feeds)
    {
        $tmp = array();

        foreach ($enabled_feeds as $feed) {
            $sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
            $sth->execute([$feed, $_SESSION['uid']]);

            if ($row = $sth->fetch()) {
                array_push($tmp, $feed);
            }
        }

        return $tmp;
    }

    public function convert()
    {
        $article_id = (int) $_REQUEST["id"];

        $sth = $this->pdo->prepare("SELECT title, content FROM ttrss_entries WHERE id = ?");
        $sth->execute([$article_id]);

        if ($row = $sth->fetch()) {
            $article = array(
				'title' => $row["title"],
				'content' => $row["content"]
				);
            $output = $this->process_article($article);
			print json_encode($output);            
        }
    }
}