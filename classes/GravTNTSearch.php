<?php
namespace Grav\Plugin\TNTSearch;

use Grav\Common\Grav;
use Grav\Plugin\TNTSearch\GravConnector;
use TeamTNT\TNTSearch\TNTSearch;

class GravTNTSearch
{
    protected $tnt;
    protected $options;

    public function __construct($options = null)
    {
        $search_type = Grav::instance()['config']->get('plugins.tntsearch.search_type');

        $defaults = ['json' => true, 'search_type' => $search_type, 'limit' => 20, 'as_you_type' => true];
        
        $this->tnt = new TNTSearch();

        $data_path = Grav::instance()['locator']->findResource('user://data', true) . '/tntsearch';

        // merge any passed-in options
        if ($options) {
            $this->options = array_merge($defaults, $options);
        } else {
            $this->options = $defaults;
        }

        if (!file_exists($data_path)) {
            mkdir($data_path);
        }

        $this->tnt->loadConfig([
            "storage"   => $data_path,
            "driver"    => 'sqlite',
        ]);

    }

    public function search($query) {

        $this->tnt->selectIndex('grav.index');
        $this->tnt->asYouType = $this->options['as_you_type'];

        $limit = intval($this->options['limit']);

        if ($this->options['search_type'] == 'boolean') {
            $results = $this->tnt->searchBoolean($query, $limit);
        } else {
            if ($this->options['search_type'] == 'fuzzy') {
                $this->tnt->fuzziness = true;
            }
            $results = $this->tnt->search($query, $limit);
        }

        return $this->processResults($results, $query);
    }

    protected function processResults($res, $query)
    {
        $data = ['hits' => [], 'number_of_hits' => $res['hits'], 'execution_time' => $res['execution_time']];

        $pages = Grav::instance()['pages'];

        foreach ($res['ids'] as $path) {
            $page = $pages->dispatch($path);

            if ($page) {
                $content = $this->getCleanContent($page);
                $title = $page->title();

                $relevant = $this->tnt->snippet($query, $content);

                if (strlen($relevant) <= 6) {
                    $relevant = substr($content, 0, 300);
                }

                $data['hits'][] = [
                    'link' => $path,
                    'title' =>  $this->tnt->highlight($title, $query, 'em', ['wholeWord' => false]),
                    'content' =>  $this->tnt->highlight($relevant, $query, 'em', ['wholeWord' => false]),
                ];
            }


        }
        if ($this->options['json']) {
            return json_encode($data, JSON_PRETTY_PRINT);
        } else {
            return $data;
        }
    }

    public static function getCleanContent($page)
    {
        $twig = Grav::instance()['twig'];
        $header = $page->header();

        if (isset($header->tntsearch['template'])) {
            $processed_page = $twig->processTemplate($header->tntsearch['template'] . '.html.twig', ['page' => $page]);
            $content =$processed_page;
        } else {
            $content = $page->content();
        }

        $content = preg_replace('/[ \t]+/', ' ', preg_replace('/\s*$^\s*/m', "\n", strip_tags($content)));

        return $content;
    }

    public function indexGravPages()
    {
        $this->tnt->setDatabaseHandle(new GravConnector);
        $indexer = $this->tnt->createIndex('grav.index');
        $indexer->run();
    }

}
