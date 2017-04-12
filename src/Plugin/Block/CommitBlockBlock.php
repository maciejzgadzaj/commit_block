<?php

namespace Drupal\commit_block\Plugin\Block;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a commit block.
 *
 * @Block(
 *   id = "commit_block_block",
 *   admin_label = @Translation("Commit block"),
 *   category = @Translation("Social")
 * )
 */
class CommitBlockBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * Constructs a CommitBlockBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\Client $http_client
   *   The Guzzle HTTP client.
   * @param ConfigFactory $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, Client $http_client, ConfigFactory $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'user_id' => '',
      'count' => 4,
      'width' => 150,
      'height' => 150,
      'img_resolution' => 'thumbnail',
      'cache_time_minutes' => 1440,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['drupal_user_id'] = array(
      '#type' => 'number',
      '#title' => $this->t('Drupal user id'),
      '#default_value' => $this->configuration['drupal_user_id'],
    );

    $form['github_user_id'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('GitHub user id'),
      '#default_value' => $this->configuration['github_user_id'],
    );

    $form['count'] = array(
      '#type' => 'number',
      '#title' => $this->t('Number of commits to display'),
      '#default_value' => $this->configuration['count'],
    );

    $form['cache_time_minutes'] = array(
      '#type' => 'number',
      '#title' => $this->t('Cache time'),
      '#field_suffix' => $this->t('minutes'),
      '#default_value' => $this->configuration['cache_time_minutes'],
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    if ($form_state->hasAnyErrors()) {
      return;
    }
    else {
      $this->configuration['drupal_user_id'] = $form_state->getValue('drupal_user_id');
      $this->configuration['github_user_id'] = $form_state->getValue('github_user_id');
      $this->configuration['count'] = $form_state->getValue('count');
      $this->configuration['cache_time_minutes'] = $form_state->getValue('cache_time_minutes');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Build a render array to return the commits.
    $build = array();
    $module_config = $this->configFactory->get('commit_block.settings')->get();

    // If no configuration was saved, don't attempt to build block.
    if (empty($this->configuration['drupal_user_id']) && empty($module_config['github_user_id'])) {
      return $build;
    }

    $urls = [
      'drupal' => "https://www.drupal.org/user/{$this->configuration['drupal_user_id']}/track/code/feed",
      'github' => "https://github.com/{$this->configuration['github_user_id']}.atom",
    ];

    $commits = [];
    foreach ($urls as $source => $url) {

      $result = $this->fetchData($url);

      $process_callback = 'process' . ucfirst($source);
      $commits = array_merge($commits, $this->$process_callback($result));
    }

    usort($commits, function ($a, $b) {
      return SortArray::sortByKeyInt($a, $b, 'timestamp');
    });
    $commits = array_reverse($commits);
    $commits = array_slice($commits, 0, $this->configuration['count']);

    foreach ($commits as $commit) {
      $build['commits'][$commit['hash']] = array(
        '#theme' => 'commit_block_commit',
        '#commit' => $commit,
      );
    }

    // Add css.
    if (!empty($build)) {
      $build['#attached']['library'][] = 'commit_block/commit_block';
    }

    // Cache for a day.
    $build['#cache']['keys'] = [
      'block',
      'commit_block',
      $this->configuration['id'],
    ];
    $build['#cache']['context'][] = 'languages:language_content';
    $build['#cache']['max_age'] = $this->configuration['cache_time_minutes'] * 60;

    return $build;
  }

  /**
   * Sends a http request to the API server.
   *
   * @param string $url
   *   URL for http request.
   *
   * @return bool|mixed
   *   The encoded API response or FALSE.
   */
  protected function fetchData($url) {
    try {
      $response = $this->httpClient->get($url, array('headers' => array('Accept' => 'application/xml')));
      if ($body = $response->getBody()) {
        $xml = simplexml_load_string($body);
        $json = Json::encode($xml);
        $array = Json::decode($json);
        return $array;
      }
    }
    catch (RequestException $e) {
      return FALSE;
    }
  }

  /**
   * Processes Drupal commits received from the API.
   *
   * @TODO: This should be a plugin.
   *
   * @param array $result
   *   An array of Drupal API response elements.
   *
   * @return array
   *   An array of Drupal commits in unified format.
   */
  protected function processDrupal($result) {
    $commits = [];

    if (!empty($result['channel']['item'])) {
      foreach ($result['channel']['item'] as $item) {

        $link_matches = $description_matches = [];
        preg_match('/http:\/\/drupalcode.org\/(.*)\/tree.*<pre>(.*)<\/pre>/isU', $item['description'], $description_matches);
        preg_match('/https:\/\/www.drupal.org\/commitlog\/commit\/.*\/(.*)/is', $item['link'], $link_matches);

        $commits[] = [
          'title' => $item['title'],
          'project' => !empty($description_matches[1]) ? trim($description_matches[1]) : '',
          'message' => !empty($description_matches[2]) ? trim($description_matches[2]) : '',
          'hash' => !empty($link_matches[1]) ? trim($link_matches[1]) : '',
          'date' => $item['pubDate'],
          'timestamp' => strtotime($item['pubDate']),
          'link' => $item['link'],
          'source' => 'drupal',
        ];

      }
    }

    return $commits;
  }

  /**
   * Processed GitHub commits received from the API.
   *
   * @TODO: This should be a plugin.
   *
   * @param array $result
   *   An array of GitHub API response elements.
   *
   * @return array
   *   An array of GitHub commits in unified format.
   */
  protected function processGithub($result) {
    $commits = [];

    if (!empty($result['entry'])) {
      foreach ($result['entry'] as $item) {

        // Use only commits, skip everything else.
        if (strpos($item['id'], 'PushEvent') === FALSE) {
          continue;
        }

        $content_matches = [];
        preg_match('/target:repo" rel="noreferrer">.*\/(.*)<\/a>.*\/commit\/(.*)".*<blockquote>(.*)<\/blockquote>/isU', $item['content'], $content_matches);

        $commits[] = [
          'title' => $item['title'],
          'project' => !empty($content_matches[1]) ? trim($content_matches[1]) : '',
          'hash' => !empty($content_matches[2]) ? trim($content_matches[2]) : '',
          'message' => !empty($content_matches[3]) ? trim($content_matches[3]) : '',
          'date' => $item['published'],
          'timestamp' => strtotime($item['published']),
          'link' => $item['link'],
          'source' => 'github',
        ];

      }
    }

    return $commits;
  }

}
