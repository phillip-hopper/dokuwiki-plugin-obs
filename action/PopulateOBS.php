<?php
/**
 * Name: PopulateOBS.php
 * Description: A Dokuwiki action plugin to handle the  button click.
 *
 * Author: Phil Hopper
 * Date:   2014-12-10
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

class action_plugin_door43obs_PopulateOBS extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handle_ajax_call_unknown');
    }

    /**
     * [Custom event handler which performs action]
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */
    public function handle_ajax_call_unknown(Doku_Event &$event,
        /** @noinspection PhpUnusedParameterInspection */ $param) {

        if ($event->data !== 'create_obs_now') return;

        //no other ajax call handlers needed
        $event->stopPropagation();
        $event->preventDefault();


        $this->initialize_obs_content();
    }

    private function initialize_obs_content() {

        global $conf;
        global $INPUT;

        header('Content-Type: text/plain');

        // get the iso codes for the source and destination languages
        $srcIso = $INPUT->str('sourceLang');
        $dstIso = $this->get_iso_from_language_name_string($INPUT->str('destinationLang'));

        // check if the destination namespace exists
        $pagesDir = $conf['datadir'];
        $dstNamespaceDir = $pagesDir . DS . $dstIso;
        if (!$this->check_namespace($dstNamespaceDir)) {

            // if not found, report an error
            echo sprintf($this->get_error_message('obsNamespaceNotFound'), $dstIso);
            return;
        }

        // check if the source obs directory exists
        $srcDir = $pagesDir . DS . $srcIso . DS . 'obs';
        if (!is_dir($srcDir)) {

            // if not found, report an error
            echo sprintf($this->get_error_message('obsSourceDirNotFound'), $srcIso);
            return;
        }

        // check if the destination obs directory already exists
        $dstDir = $dstNamespaceDir . DS . 'obs';
        if (is_dir($dstDir)) {

            // if the directory exists, are there txt files in it?
            $files = glob($dstDir . DS . '*.txt', GLOB_NOSORT);
            if (!empty($files) && (count($files) > 5)) {

                // if there are, report an error
                echo sprintf($this->get_success_message('obsDestinationDirExists'), "/$dstIso/obs", "$dstIso/obs");
                return;
            }
        }

        // some files will come from the templates directory
        $templateDir = $pagesDir . '/templates/obs3/obs';

        // Now copy the obs files from $srcDir to $dstDir
        $this->copy_obs_files($srcDir, $dstDir, $templateDir, $srcIso, $dstIso);

        // update home.txt
        $templateDir = $pagesDir . '/templates';
        $this->update_home_txt($templateDir, $dstNamespaceDir, $dstIso);

        // update sidebar.txt
        $this->update_sidebar_txt($templateDir, $dstNamespaceDir, $dstIso);

        // make uwadmin status page
        $adminDir = $pagesDir . "/en/uwadmin";
        $this->copy_status_txt($templateDir, $adminDir, $dstIso);

        // update changes pages
        $script = '/var/www/vhosts/door43.org/tools/obs/dokuwiki/obs-gen-changes-pages.sh';
        if (is_file($script))
            shell_exec($script);

        // git add, commit, push
        $this->git_push($adminDir, 'Added uwadmin obs page for ' . $dstIso);
        $this->git_push(dirname($dstDir), 'Initial import of OBS');

        echo sprintf($this->get_success_message('obsCreatedSuccess'), $dstIso, "/$dstIso/obs");
    }

    private function get_error_message($langStringKey) {
        return '<span style="color: #990000;">' . $this->getLang($langStringKey) . '</span><br>';
    }

    private function get_success_message($langStringKey) {
        return '<span style="color: #005500;">' . $this->getLang($langStringKey) . '</span><br>';
    }

    private function get_iso_from_language_name_string($languageName) {

        // extract iso code from the destination language field, i.e.: "English (en)"
        $pattern = '/\([^\(\)]+\)$/';
        $matches = array();
        if (preg_match($pattern, $languageName, $matches) === 1)
            return preg_replace('/\(|\)/', '', $matches[0]);

        // if no matches, hopefully $languageName is the iso
        return $languageName;
    }

    /**
     * Check if a namespace exists.
     * @param $namespaceDir
     * @return bool
     */
    private function check_namespace($namespaceDir) {
        return is_dir($namespaceDir);
    }

    private function copy_obs_files($srcDir, $dstDir, $templateDir, $srcIso, $dstIso) {

        if (!is_dir($dstDir))
            mkdir($dstDir, 0755);

        // create the 01.txt through 50.txt source files
        $this->create_files_from_json($srcIso, $dstDir);

        // copy some files from source directory
        $files = array('back-matter.txt', 'front-matter.txt', 'cover-matter.txt');
        foreach($files as $file) {

            $srcFile = $srcDir . DS . $file;
            if (!is_file($srcFile)) continue;

            $outFile = $dstDir . DS . $file;
            copy($srcFile, $outFile);
            chmod($outFile, 0644);
        }

        // copy these files from /templates/obs3/obs
        $files = array('sidebar.txt', 'stories.txt');
        foreach($files as $file) {

            $srcFile = $templateDir . DS . $file;
            $outFile = $dstDir . DS . $file;
            $this->copy_template_file($srcFile, $outFile, $dstIso);
        }

        // create the obs.txt home page
        $srcFile = dirname($templateDir) . DS . 'obs.txt';
        $outFile = dirname($dstDir) . DS . 'obs.txt';
        $this->copy_template_file($srcFile, $outFile, $dstIso);
    }

    private function create_files_from_json($srcIso, $dstDir) {

        $src = file_get_contents("https://api.unfoldingword.org/obs/txt/1/en/obs-{$srcIso}.json");
        $srcClass = json_decode($src, true);

        // chapters
        //   frames
        //     id: "01-01"
        //     img: "url"
        //     text: "frame text"
        //   number: "01",
        //   ref: "A Bible story from: Genesis 1-2",
        //   title: "1. The Creation"
        foreach($srcClass['chapters'] as $chapter) {

            $outFile = $dstDir . DS . $chapter['number'] . '.txt';

            $text = "====== {$chapter['title']} ======\n\n";

            foreach($chapter['frames'] as $frame) {
                $text .= $this->add_frame($frame['img'], $frame['text']);
            }

            $text .= "//{$chapter['ref']}//\n\n\n";

            file_put_contents($outFile, $text);
            chmod($outFile, 0644);
        }

        // app_words
        $outFile = $dstDir . DS . 'app_words.txt';
        $text = "//Translation for the unfoldingWord mobile app interface//\n";

        foreach($srcClass['app_words'] as $key => $value) {
            $text .= "\n\n{$key}: {$value}\n";
        }

        file_put_contents($outFile, $text);
        chmod($outFile, 0644);
    }

    private function add_frame($imgUrl, $text) {

        // the image
        $returnVal = "\n{{" . $imgUrl . "}}\n\n";

        // the text
        $returnVal .= "\n{$text}\n\n";

        // leave room for the translation
        $returnVal .= "\n\n";

        return $returnVal;
    }

    private function copy_template_file($srcFile, $outFile, $dstIso) {

        $text = file_get_contents($srcFile);
        file_put_contents($outFile, str_replace('LANGCODE', $dstIso, $text));
        chmod($outFile, 0644);
    }

    private function update_home_txt($templateDir, $dstNamespaceDir, $dstIso) {

        $homeFile = $dstNamespaceDir . DS . 'home.txt';
        if (!is_file($homeFile)) {

            $srcFile = $templateDir . DS . 'home.txt';
            $this->copy_template_file($srcFile, $homeFile, $dstIso);
        }

        $text = file_get_contents($homeFile);
        $text .= "\n===== Resources =====\n\n  * **[[{$dstIso}:obs|Open Bible Stories ({$dstIso})]]**";
        file_put_contents($homeFile, $text);
    }

    private function update_sidebar_txt($templateDir, $dstNamespaceDir, $dstIso) {

        $sidebarFile = $dstNamespaceDir . DS . 'sidebar.txt';
        if (!is_file($sidebarFile)) {

            $srcFile = $templateDir . DS . 'sidebar.txt';
            $this->copy_template_file($srcFile, $sidebarFile, $dstIso);
        }

        $text = file_get_contents($sidebarFile);
        $text .= "\n**Resources**\n\n  * [[{$dstIso}:obs|Open Bible Stories ({$dstIso})]]\n\n**Latest OBS Status**\n{{page>en:uwadmin:{$dstIso}:obs:status}}";
        file_put_contents($sidebarFile, $text);
    }

    private function copy_status_txt($templateDir, $adminDir, $dstIso) {

        $adminDir .= "/{$dstIso}/obs";
        if (!is_dir($adminDir)) mkdir($adminDir, 0755);

        $statusFile = $adminDir . DS . 'status.txt';
        $srcFile = $templateDir . DS . 'status.txt';

        $text = file_get_contents($srcFile);
        $text = str_replace('ORIGDATE', date('Y-m-d'), $text);

        file_put_contents($statusFile, $text);
    }

    private function git_push($dir, $msg) {

        $originalDir = getcwd();

        chdir($dir);

        // the 2>&1 redirect sends errorOut to stdOut
        $result1 = shell_exec('git add . 2>&1');
        $result2 = shell_exec('git commit -am "' . $msg . '" 2>&1');
        $result3 = shell_exec('git push origin master 2>&1');

        // show the git output in a development environment
        if (($_SERVER['SERVER_NAME'] == 'localhost') || ($_SERVER['SERVER_NAME'] == 'test.door43.org'))
            echo "<br>Git Response: $result1<br><br>Git Response: $result2<br><br>Git Response: $result3<br><br>";

        chdir($originalDir);
    }
}


