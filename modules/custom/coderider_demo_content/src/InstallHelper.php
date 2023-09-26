<?php

namespace Drupal\coderider_demo_content;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\block\Entity\Block;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;

/**
 * Defines a helper class for importing default content.
 *
 * @internal
 *   This code is only for use by the Umami demo: Content module.
 */
class InstallHelper implements ContainerInjectionInterface {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * State.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Term ID map.
   *
   * Used to store term IDs created in the import process against
   * vocabulary and row in the source CSV files. This allows the created terms
   * to be cross referenced when creating articles and recipes.
   *
   * @var array
   */
  protected $termIdMap;

  /**
   * Media Image CSV ID map.
   *
   * Used to store media image CSV IDs created in the import process.
   * This allows the created media images to be cross referenced when creating
   * article, recipes and blocks.
   *
   * @var array
   */
  protected $mediaImageIdMap;

  /**
   * Node CSV ID map.
   *
   * Used to store node CSV IDs created in the import process. This allows the
   * created nodes to be cross referenced when creating blocks.
   *
   * @var array
   */
  protected $nodeIdMap;

  /**
   * Paragraph type customer_reviews ID map
   *
   * Used to store paragraph type customer_reviews IDs and revision ids
   * to use as reference in block entities
   *
   * @var array
   */
  protected $paragrapsCusReviewsIdMap;

  /**
   * Paragraph type our_vision ID map
   *
   * Used to store paragraph type our_vision IDs and revision ids
   * to use as reference in block entities
   *
   * @var array
   */
  protected $paragrapsOurVisionIdMap;

  /**
   * Paragraph type services ID map
   *
   * Used to store paragraph type services IDs and revision ids
   * to use as reference in block entities
   *
   * @var array
   */
  protected $paragrapsServicesIdMap;

  /**
   * Block display configurations
   *
   * Used to store Block display configurations
   * will populate after block entity created
   * later will create configuration entity using this array
   *
   * @var array
   */
  protected $blockConfigurations;

  /**
   * Block display configurations for bacis page
   *
   * Used to store Block display configurations
   * will populate after block entity created
   * later will create page layout havige blocks with config
   *
   * @var array
   */
  protected $basicPageLayoutFonfigs;

  /**
   * The module's path.
   */
  protected string $module_path;

  /**
   * Constructs a new InstallHelper object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module handler.
   * @param \Drupal\Core\State\StateInterface $state
   *   State service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, ModuleHandlerInterface $moduleHandler, StateInterface $state, FileSystemInterface $fileSystem) {
    $this->entityTypeManager = $entityTypeManager;
    $this->moduleHandler = $moduleHandler;
    $this->state = $state;
    $this->fileSystem = $fileSystem;
    $this->termIdMap = [];
    $this->mediaImageIdMap = [];
    $this->nodeIdMap = [];
    $this->paragrapsCusReviewsIdMap = [];
    $this->paragrapsOurVisionIdMap = [];
    $this->paragrapsServicesIdMap = [];
    $this->blockConfigurations = [];
    $this->basicPageLayoutFonfigs = [];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('state'),
      $container->get('file_system')
    );
  }

  /**
   * Imports default contents.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function importContent() {
    $this->getModulePath()
      ->importContentFromFile('taxonomy_term', 'atricle_category')
      ->importContentFromFile('media', 'image')
      ->importContentFromFile('paragraph', 'customer_reviews')
      ->importContentFromFile('paragraph', 'our_vision')
      ->importContentFromFile('paragraph', 'paragraph_services')
      ->importContentFromFile('node', 'page')
      ->importContentFromFile('block_content', 'basic')
      ->importContentFromFile('block_content', 'hero_banner')
      ->importContentFromFile('block_content', 'home_page_about_block')
      ->importContentFromFile('block_content', 'our_mission')
      ->importContentFromFile('block_content', 'services')
      ->importContentFromFile('block_content', 'testimonials')
      ->importContentFromFile('block_content', 'why_us')
      ->importContentFromFile('node', 'article')
      ->importContentFromFile('node', 'case_studies')
      ->importContentFromFile('node', 'our_professionals')
      ->createMenuItems()
      ->setBlockConfigurations()
      ->createBasicPageLayout();

  }

  /**
   * Set module_path variable.
   *
   * @return $this
   */
  protected function getModulePath() {
    $this->module_path = $this->moduleHandler->getModule('coderider_demo_content')->getPath();
    return $this;
  }

  /**
   * Read multilingual content.
   *
   * @param string $filename
   *   Filename to import.
   *
   * @return array
   *   An array of two items:
   *     1. All multilingual content that was read from the files.
   */
  protected function readContent($filename) {
    $default_content_path = $this->module_path . "/default_content/";
    if (file_exists($default_content_path . $filename) &&
      ($handle = fopen($default_content_path . $filename, 'r')) !== FALSE) {
      $header = fgetcsv($handle);
      $line_counter = 0;
      while (($content = fgetcsv($handle)) !== FALSE) {
        $keyed_content[$line_counter] = array_combine($header, $content);
        $line_counter++;
      }
      fclose($handle);
    }
    return $keyed_content;
  }

  /**
   * Retrieves the Term ID of a term saved during the import process.
   *
   * @param string $vocabulary
   *   Machine name of vocabulary to which it was saved.
   * @param int $term_csv_id
   *   The term's ID from the CSV file.
   *
   * @return int
   *   Term ID, or 0 if Term ID could not be found.
   */
  protected function getTermId($vocabulary, $term_csv_id) {
    if (array_key_exists($vocabulary, $this->termIdMap) && array_key_exists($term_csv_id, $this->termIdMap[$vocabulary])) {
      return $this->termIdMap[$vocabulary][$term_csv_id];
    }
    return 0;
  }

  /**
   * Saves a Term ID generated when saving a taxonomy term.
   *
   * @param string $vocabulary
   *   Machine name of vocabulary to which it was saved.
   * @param int $term_csv_id
   *   The term's ID from the CSV file.
   * @param int $tid
   *   Term ID generated when saved in the Drupal database.
   */
  protected function saveTermId($vocabulary, $term_csv_id, $tid) {
    $this->termIdMap[$vocabulary][$term_csv_id] = $tid;
  }


  /**
   * Retrieves the Media Image ID of a media image saved during the import process.
   *
   * @param int $media_image_csv_id
   *   The media image's ID from the CSV file.
   *
   * @return int
   *   Media Image ID, or 0 if Media Image ID could not be found.
   */
  protected function getMediaImageId($media_image_csv_id) {
    if (array_key_exists($media_image_csv_id, $this->mediaImageIdMap)) {
      return $this->mediaImageIdMap[$media_image_csv_id];
    }
    return 0;
  }

  /**
   * Saves a Media Image ID generated when saving a media image.
   *
   * @param int $media_image_csv_id
   *   The media image's ID from the CSV file.
   * @param int $media_image_id
   *   Media Image ID generated when saved in the Drupal database.
   */
  protected function saveMediaImageId($media_image_csv_id, $media_image_id) {
    $this->mediaImageIdMap[$media_image_csv_id] = $media_image_id;
  }

  /**
   * Retrieves the node path of node CSV ID saved during the import process.
   *
   * @param string $content_type
   *   Current content type.
   * @param string $node_csv_id
   *   The node's ID from the CSV file.
   *
   * @return string
   *   Node path, or 0 if node CSV ID could not be found.
   */
  protected function getNodePath($content_type, $node_csv_id) {
    if (array_key_exists($content_type, $this->nodeIdMap) &&
        array_key_exists($node_csv_id, $this->nodeIdMap[$content_type])) {
      return $this->nodeIdMap[$content_type][$node_csv_id];
    }
    return 0;
  }

  /**
   * Saves a node CSV ID generated when saving content.
   *
   * @param string $content_type
   *   Current content type.
   * @param string $node_csv_id
   *   The node's ID from the CSV file.
   * @param string $node_url
   *   Node's URL alias when saved in the Drupal database.
   */
  protected function saveNodePath($content_type, $node_csv_id, $node_url) {
    $this->nodeIdMap[$content_type][$node_csv_id] = $node_url;
  }


  /**
   * Process terms for a given vocabulary and filename.
   *
   * @param array $data
   *   Data of line that was read from the file.
   * @param string $vocabulary
   *   Machine name of vocabulary to which we should save terms.
   *
   * @return array
   *   Data structured as a term.
   */
  protected function processTerm(array $data, $vocabulary) {
    $term_name = trim($data['term']);

    // Prepare content.
    $values = [
      'name' => $term_name,
      'vid' => $vocabulary
    ];
    return $values;
  }

  /**
   * Process images into media entities.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a image.
   */
  protected function processImage(array $data) {
    

    $image_path = $this->module_path . '/default_content/images/' . $data['image'];
    // Prepare content.
    $values = [
      'bundle' => 'image',
      'field_media_image' => [
        'target_id' => $this->createFileEntity($image_path),
        'alt' => $data['alt'],
      ],
    ];
    return $values;
  }

  /**
   * Process pages data into page node structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a page node.
   */
  protected function processPage(array $data) {
    // Prepare content.
    $values = [
      'type' => 'page',
      'title' => $data['title'],
      'moderation_state' => 'published'
    ];
    // Fields mapping starts.
    // Set body field.
    if (!empty($data['body'])) {
      $values['body'] = [
        ['value' => $data['body'],
         'format' => 'full_html']];
    }
    // Set node alias if exists.
    if (!empty($data['alias'])) {
      $values['path'] = [['alias' => $data['alias']]];
    }

    return $values;
  }

  /**
   * Process Case Studies data into Case Studies node structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a Case Studies node.
   */
  protected function processCaseStudies(array $data) {
    $values = [
      'type' => 'case_studies',
      // Title field.
      'title' => $data['title'],
      'moderation_state' => 'published'
    ];
    if (!empty($data['body'])) {
      $values['body'] = [
        ['value' => $data['body'],
         'format' => 'basic_html']];
    }

    // Set field_media_image field.
    if (!empty($data['media_image'])) {
      $values['field_media_image'] = [
        'target_id' => $this->getMediaImageId($data['media_image']),
      ];
    }
    
    return $values;
  }

  /**
   * Process article data into article node structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as an article node.
   */
  protected function processArticle(array $data) {
    // Prepare content.
    $values = [
      'type' => 'article',
      'title' => $data['title'],
      'moderation_state' => 'published'
    ];
    // Fields mapping starts.
    // Set body field.
    if (!empty($data['body'])) {
      $values['body'] = [['value' => $data['body'], 'format' => 'full_html']];
    }
    
    // Set field_media_image field.
    if (!empty($data['media_image'])) {
      $values['field_media_image'] = [
        'target_id' => $this->getMediaImageId($data['media_image']),
      ];
    }
    // Set field_tags if exists.
    if (!empty($data['category'])) {
      $values['field_category'] = [
        'target_id' => $this->getTermId("atricle_category",$data['category']),
      ];
    }
    return $values;
  }

  /**
   * Process Our Professionals data into Our Professionals node structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as an Our Professionals node.
   */
  protected function processoOurProfessionals(array $data) {
    // Prepare content.
    $values = [
      'type' => 'our_professionals',
      'title' => $data['title'],
      'moderation_state' => 'published'
    ];
    if (!empty($data['job_type'])) {
      $values['field_job_type'] = [
        'value' => $data['job_type'],
      ];
    }
    if (!empty($data['media_image'])) {
      $values['field_media_image'] = [
        'target_id' => $this->getMediaImageId($data['media_image']),
      ];
    }
    if (!empty($data['member_about'])) {
      $values['field_member_about'] = [
        'value' => $data['member_about'],
      ];
    }
    if (!empty($data['email'])) {
      $values['field_member_email'] = [
        'value' => $data['email'],
      ];
    }
    if (!empty($data['phone'])) {
      $values['field_member_phone'] = [
        'value' => $data['phone'],
      ];
    }
    if (!empty($data['facebook'])) {
      $values['field_member_social'] = [
        [
          'uri' => $data['facebook'],
          'title' => 'facebook',
        ],
        [
          'uri' => $data['twitter'],
          'title' => 'twitter',
        ],
        [
          'uri' => $data['instagram'],
          'title' => 'instagram',
        ],
        [
          'uri' => $data['github'],
          'title' => 'github',
        ],
      ];
    }
    if (!empty($data['professional_statement'])) {
      $values['field_professional_statement'] = [
        'value' => $data['professional_statement'],
      ];
    }

    if (!empty($data['specializations'])) {
      $specializationValues = explode("\n", $data['specializations']);
      foreach($specializationValues as $spValue){
        $values['field_specializations'][] = ['value' => $spValue];
      }
      
    }
    return $values;
  }
  /**
   * Process home_page_about_block data into home_page_about_block block structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a home_page_about_block block.
   */
  protected function processAboutBlock(array $data) {
    $values = [
      'info' => $data['info'],
      'type' => 'home_page_about_block',

      'field_media_image' => [
        'target_id' => $this->getMediaImageId($data['media_image']),
      ],
      'field_summery' => [
        'value' => $data['summery'],
      ],
      'field_title' => [
        'value' => $data['title'],
      ],
      'field_white_box_content' => [
        'value' => $data['white_box_content'],
      ],
      'field_white_box_title' => [
        'value' => $data['white_box_title'],
      ],
      
    ];
    if(!empty($data['link'])){
        $values['field_link'] = [
          'uri' => 'internal:'. $data['link'],
          'title' => $data['link_text'],
        ];
      }

    if (!empty($data['body'])) {
      $values['body'] = [['value' => $data['body'], 'format' => 'basic_html']];
    }
    return $values;
  }
  
  /**
   * Process our_mission data into our_mission block structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a our_mission block.
   */
  protected function processOurMissionBlock(array $data) {
    $values = [
      'info' => $data['info'],
      'type' => 'our_mission',
      
      'field_our_goal' => $this->paragrapsOurVisionIdMap
    ];

    
    return $values;

  }

  /**
   * Process services data into services block structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a services block.
   */
  protected function processServicesBlock(array $data) {
    $values = [
      'info' => $data['info'],
      'type' => 'services',
      'field_media_image' => [
        'target_id' => $this->getMediaImageId($data['media_image']),
      ],
      
      'field_service_wrapper' => $this->paragrapsServicesIdMap
    ];

    
    return $values;

  }
  
   /**
   * Process testimonials data into testimonials block structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a testimonials block.
   */
  protected function processTestimonialsBlock(array $data) {
    $values = [
      'info' => $data['info'],
      'type' => 'testimonials',
      'field_link' => [
        'uri' => 'internal:'. $data['link'],
        'title' => $data['link_text'],
      ],
      'field_sub_title' => [
        'value' => $data['sub_title'],
      ],
      
      'field_testimonial' => $this->paragrapsCusReviewsIdMap
    ];

    if (!empty($data['body'])) {
      $values['body'] = [['value' => $data['body'], 'format' => 'basic_html']];
    }
    return $values;

  }
  
  /**
   * Process why_us data into why_us block structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a why_us block.
   */
  protected function processWhyUsBlock(array $data) {
    $values = [
      'info' => $data['info'],
      'type' => 'why_us',
      'field_link' => [
        'uri' => 'internal:'. $data['link'],
        'title' => $data['link_text'],
      ],
      'field_media_image' => [
        'target_id' => $this->getMediaImageId($data['media_image']),
      ]
    ];

    if (!empty($data['body'])) {
      $values['body'] = [['value' => $data['body'], 'format' => 'basic_html']];
    }
    return $values;

  }
  /**
   * Process hero_banner data into hero_banner block structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a hero_banner block.
   */
  protected function processHeroBannerBlock(array $data) {
    $values = [
      'info' => $data['info'],
      'type' => 'hero_banner',
      'field_media_image' => [
        'target_id' => $this->getMediaImageId($data['media_image']),
      ],
      'field_link' => [
        'uri' => 'internal:'.$data['link'],
        'title' => $data['link_text'],
      ],
      'field_title' => [
        'value' => $data['title'],
      ],
    ];
    if (!empty($data['body'])) {
      $values['body'] = [
        ['value' => $data['body'],
         'format' => 'full_html']];
    }
    return $values;
  }
  

  /**
   * Process Customer Review paragraph item structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a customer_reviews paragraph.
   */
  protected function processParagraphCusRev(array $data) {
    
    $values = [
      'type' => 'customer_reviews',
      'field_customer_designation' => [
        'value' => $data['field_customer_designation'],
      ],
      /*'field_content_link' => [
        'uri' => 'internal:/' . $node_url,
        'title' => $data['field_content_link_title'],
      ],*/
      'field_customer_name' => [
        'value' => $data['field_customer_name'],
      ],
      'field_customer_review_message' => [
        'value' => $data['field_customer_review_message'],
      ],
      'field_customer_image' => [
        'target_id' => $this->getMediaImageId($data['field_customer_image']),
      ],
    ];
    return $values;
  }

  /**
   * Process our_vision paragraph item structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a our_vision paragraph.
   */
  protected function processParagraphOurVision(array $data) {
    
    $values = [
      'type' => 'our_vision',
      'field_body' => [
        'value' => $data['field_body']
      ],
      /*'field_content_link' => [
        'uri' => 'internal:/' . $node_url,
        'title' => $data['field_content_link_title'],
      ],*/
      'field_title' => [
        'value' => $data['field_title'],
      ],
    ];
    return $values;
  }

  /**
   * Process services paragraph item structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a services paragraph.
   */
  protected function processParagraphServices(array $data) {
    
    $values = [
      'type' => 'services',
      'field_service_detail' => [
        'value' => $data['field_service_detail'],
        'format' => 'basic_html'
      ],
      'field_service_icon' => [
        'target_id' => $this->getMediaImageId($data['field_service_icon']),
      ],
      'field_service_image' => [
        'target_id' => $this->getMediaImageId($data['field_service_image']),
      ],
      'field_service_name' => [
        'value' => $data['field_service_name']
      ],
    ];
    return $values;
  }

  /**
   * Process basic data into basic block structure.
   *
   * @param array $data
   *   Data of line that was read from the file.
   *
   * @return array
   *   Data structured as a basic block.
   */
  protected function processBasicBlock(array $data) {
    $values = [
      'info' => $data['info'],
      'type' => 'basic'
    ];

    if (!empty($data['body'])) {
      $values['body'] = [['value' => $data['body'], 'format' => 'full_html']];
    }
    return $values;
  }

  /**
   * Process content into a structure that can be saved into Drupal.
   *
   * @param string $bundle_machine_name
   *   Current bundle's machine name.
   * @param array $content
   *   Current content array that needs to be structured.
   *
   * @return array
   *   Structured content.
   */
  protected function processContent($bundle_machine_name, array $content) {
    switch ($bundle_machine_name) {
      
      case 'our_professionals':
        $structured_content = $this->processoOurProfessionals($content);
        break;

      case 'case_studies':
        $structured_content = $this->processCaseStudies($content);
        break;

      case 'article':
        $structured_content = $this->processArticle($content);
        break;

      case 'page':
        $structured_content = $this->processPage($content);
        break;

      case 'why_us':
        $structured_content = $this->processWhyUsBlock($content);
        break;

      case 'testimonials':
        $structured_content = $this->processTestimonialsBlock($content);
        break;

      case 'services':
        $structured_content = $this->processServicesBlock($content);
        break;

      case 'our_mission':
        $structured_content = $this->processOurMissionBlock($content);
        break;

      case 'home_page_about_block':
        $structured_content = $this->processAboutBlock($content);
        break;

      case 'hero_banner':
        $structured_content = $this->processHeroBannerBlock($content);
        break;

      case 'basic':
        $structured_content = $this->processBasicBlock($content);
        break;

      case 'customer_reviews':
        $structured_content = $this->processParagraphCusRev($content);
        break;

      case 'our_vision':
        $structured_content = $this->processParagraphOurVision($content);
        break;

      case 'paragraph_services':
        $structured_content = $this->processParagraphServices($content);
        break;

      case 'image':
        $structured_content = $this->processImage($content);
        break;

      case 'atricle_category':
        $structured_content = $this->processTerm($content, $bundle_machine_name);
        break;

      default:
        break;
    }
    return $structured_content;
  }

  /**
   * Imports content.
   *
   * @param string $entity_type
   *   Entity type to be imported
   * @param string $bundle_machine_name
   *   Bundle machine name to be imported.
   *
   * @return $this
   */
  protected function importContentFromFile($entity_type, $bundle_machine_name) {
    if($bundle_machine_name == "paragraph_services"){
      $filename = $entity_type . '/services.csv';
    }else{
      $filename = $entity_type . '/' . $bundle_machine_name . '.csv';
    }

    // Read all multilingual content from the file.
    $all_content = $this->readContent($filename);
    // Start the loop with English (default) recipes.
    foreach ($all_content as $current_content) {
      // Process data into its relevant structure.
      $structured_content = $this->processContent($bundle_machine_name, $current_content);

      // Save Entity.
      $entity = $this->entityTypeManager->getStorage($entity_type)->create($structured_content);
      //$entity = \Drupal::entityTypeManager()->getStorage($entity_type)->create($structured_content);
      $entity->save();
      $this->storeCreatedContentUuids([$entity->uuid() => $entity_type]);

      if($entity_type == "node" && $bundle_machine_name == "page"){
        if($structured_content['title'] == "Testimonials"){
          $this->basicPageLayoutFonfigs['testimonial']['node_id'] = $entity->id();

        }
        if($structured_content['title'] == "About us"){
          $this->basicPageLayoutFonfigs['about']['node_id'] = $entity->id();
        }

        

      }

      // Save block display configurations after block created
      // If we import block configuration though the yml file while installing profile
      // configuration imported before block creation and we'll get broken/missing link error
      if($entity_type == "block_content"){

        if($structured_content['info'] == "home_page_about_block"){

          $this->blockConfigurations['coderider_home_page_about_block'] = [
            'blockMachineName' => 'coderider_home_page_about_block',
            'blockWeight' => -9,
            'region' => 'content',
            'displayPages' => '<front>',
            'label' => 'Home page about block',
            'labelDisplay' => 0,
            'uuid' => $entity->uuid()
          ];

        }
        if($structured_content['info'] == "Our Services"){

          $this->blockConfigurations['coderider_ourservices'] = [
            'blockMachineName' => 'coderider_ourservices',
            'blockWeight' => -7,
            'region' => 'content',
            'displayPages' => '<front>',
            'label' => 'Our Service',
            'labelDisplay' => 1,
            'uuid' => $entity->uuid()
          ];

        }
        if($structured_content['info'] == "Why partner with us?"){

          $this->blockConfigurations['coderider_whypartnerwithus'] = [
            'blockMachineName' => 'coderider_whypartnerwithus',
            'blockWeight' => -5,
            'region' => 'content',
            'displayPages' => '<front>',
            'label' => 'Why partner with us?',
            'labelDisplay' => 1,
            'uuid' => $entity->uuid()
          ];

        }
        if($structured_content['info'] == "What our customers say about us"){
          $this->basicPageLayoutFonfigs['testimonial']['topsection']['block_one_uuid'] = $entity->uuid();

          $this->blockConfigurations['coderider_whatourcustomerssayaboutus'] = [
            'blockMachineName' => 'coderider_whatourcustomerssayaboutus',
            'blockWeight' => -4,
            'region' => 'content',
            'displayPages' => '<front>',
            'label' => 'What our customers say about us',
            'labelDisplay' => 1,
            'uuid' => $entity->uuid()
          ];

        }
        if($structured_content['info'] == "Contact"){

          $this->blockConfigurations['coderider_contact'] = [
            'blockMachineName' => 'coderider_contact',
            'blockWeight' => -11,
            'region' => 'sidebar',
            'displayPages' => "/case/*\r\n/article/*",
            'label' => 'Contact',
            'labelDisplay' => 1,
            'uuid' => $entity->uuid()
          ];

        }
        if($structured_content['info'] == "home page banner"){
          
          $this->blockConfigurations['coderider_homepagebanner'] = [
            'blockMachineName' => 'coderider_homepagebanner',
            'blockWeight' => 0,
            'region' => 'hero',
            'displayPages' => '<front>',
            'label' => 'Welcome to January Theme',
            'labelDisplay' => 1,
            'uuid' => $entity->uuid()
          ];

        }

        if($structured_content['info'] == "about page learn block top1") {
          $this->basicPageLayoutFonfigs['about']['topsection']['block_one_uuid'] = $entity->uuid();
        }

        if($structured_content['info'] == "testimonials page block fund the next") {
          $this->basicPageLayoutFonfigs['about']['topsection']['block_two_uuid'] = $entity->uuid();
          $this->basicPageLayoutFonfigs['testimonial']['topsection']['block_two_uuid'] = $entity->uuid();
        }

        if($structured_content['info'] == "Our Mission Our Vision Our History") {
          $this->basicPageLayoutFonfigs['about']['bottom_section']['block_one_uuid'] = $entity->uuid();
          $this->basicPageLayoutFonfigs['testimonial']['bottom_section']['block_one_uuid'] = $entity->uuid();
        }

        if($structured_content['info'] == "Our mission block testimonials block2") {
          $this->basicPageLayoutFonfigs['about']['bottom_section']['block_two_uuid'] = $entity->uuid();
          $this->basicPageLayoutFonfigs['testimonial']['bottom_section']['block_two_uuid'] = $entity->uuid();
        }


      }

      // Save taxonomy entity Drupal ID, so we can reference it in nodes.
      if ($entity_type == 'taxonomy_term') {
        $this->saveTermId($bundle_machine_name, $current_content['id'], $entity->id());
      }

      // Save media entity Drupal ID, so we can reference it in nodes & blocks.
      if ($entity_type == 'media') {
        $this->saveMediaImageId($current_content['id'], $entity->id());
      }

      if($entity_type == "paragraph"){
        if($bundle_machine_name == "customer_reviews"){
          $this->paragrapsCusReviewsIdMap[] = [
            'target_id' => $entity->id(),
            'target_revision_id' => $entity->getRevisionId(),
          ];
        }
        if($bundle_machine_name == "our_vision"){
          $this->paragrapsOurVisionIdMap[] = [
            'target_id' => $entity->id(),
            'target_revision_id' => $entity->getRevisionId(),
          ];
        }
        if($bundle_machine_name == "paragraph_services"){
          $this->paragrapsServicesIdMap[] = [
            'target_id' => $entity->id(),
            'target_revision_id' => $entity->getRevisionId(),
          ];
        }
      }

      
    }

    return $this;
  }

  /**
   * Deletes any content imported by this module.
   *
   * @return $this
   */
  public function deleteImportedContent() {
    $uuids = $this->state->get('coderider_demo_content_uuids', []);
    $by_entity_type = array_reduce(array_keys($uuids), function ($carry, $uuid) use ($uuids) {
      $entity_type_id = $uuids[$uuid];
      $carry[$entity_type_id][] = $uuid;
      return $carry;
    }, []);
    foreach ($by_entity_type as $entity_type_id => $entity_uuids) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      //$storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
      $entities = $storage->loadByProperties(['uuid' => $entity_uuids]);
      $storage->delete($entities);
    }
    return $this;
  }

  

  /**
   * Creates a file entity based on an image path.
   *
   * @param string $path
   *   Image path.
   *
   * @return int
   *   File ID.
   */
  protected function createFileEntity($path) {
    $filename = basename($path);
    try {
      $uri = $this->fileSystem->copy($path, 'public://' . $filename, FileSystemInterface::EXISTS_REPLACE);
    }
    catch (FileException $e) {
      $uri = FALSE;
    }
    $file = $this->entityTypeManager->getStorage('file')->create([
    //$file = \Drupal::entityTypeManager()->getStorage('file')->create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->save();
    $this->storeCreatedContentUuids([$file->uuid() => 'file']);
    return $file->id();
  }

  /**
   * Stores record of content entities created by this import.
   *
   * @param array $uuids
   *   Array of UUIDs where the key is the UUID and the value is the entity
   *   type.
   */
  protected function storeCreatedContentUuids(array $uuids) {
    $uuids = $this->state->get('coderider_demo_content_uuids', []) + $uuids;
    $this->state->set('coderider_demo_content_uuids', $uuids);
  }

  /**
   * Create menu items
   *
   * @return $this
   */
  protected function createMenuItems(){
    $items = [
      [
        "link" => "/about",
        "label" => "About Us",
        "weight" => -50
      ],
      [
        "link" => "/our-blogs",
        "label" => "Our Blogs",
        "weight" => -49
      ],
      [
        "link" => "/our-professionals",
        "label" => "Our Team",
        "weight" => -48
      ],
      [
        "link" => "/case-studies",
        "label" => "Case Studies",
        "weight" => -47
      ],
      [
        "link" => "/our-services",
        "label" => "Our Services",
        "weight" => -46
      ]
    ];

    foreach($items as $menuItem) {
      $menu_link = MenuLinkContent::create([
        'title' => $menuItem['label'],
        'link' => ['uri' => 'internal:' . $menuItem['link']],
        'weight' => $menuItem['weight'],
        'menu_name' => 'main',
        'expanded' => FALSE,
      ]);
      $menu_link->save();
    }

    $footerMenuItems = [
      [
        "link" => "/about",
        "label" => "About Us",
        "weight" => -50
      ],
      [
        "link" => "/case-studies",
        "label" => "Case Studies",
        "weight" => -49
      ],
      [
        "link" => "/contact",
        "label" => "Contact Us",
        "weight" => -48
      ],
      [
        "link" => "/terms-use",
        "label" => "Terms of Use",
        "weight" => -47
      ],
      [
        "link" => "/our-services",
        "label" => "Our Services",
        "weight" => -46
      ],
      [
        "link" => "/our-professionals",
        "label" => "Professional Association",
        "weight" => -45
      ],
      [
        "link" => "/privacy-policy",
        "label" => "Privacy Policy",
        "weight" => -44
      ],
    ];

    foreach($footerMenuItems as $footerMenuItem) {
      $footerMenulink = MenuLinkContent::create([
        'title' => $footerMenuItem['label'],
        'link' => ['uri' => 'internal:' . $footerMenuItem['link']],
        'weight' => $footerMenuItem['weight'],
        'menu_name' => 'footer-quick-links',
        'expanded' => FALSE,
      ]);
      $footerMenulink->save();
    }
    return $this;
  }

  /**
   * Create block confifuration entities
   * to place block in respected regions
   *
   * @return $this
   */
  protected function setBlockConfigurations(){

    foreach($this->blockConfigurations as $blockConfig){
      $blockConfigExist = Block::load($blockConfig['blockMachineName']);
      if($blockConfigExist){
        
        $blockConfigExist->set('theme', 'coderider');
        $blockConfigExist->set('weight', $blockConfig['blockWeight']);
        $blockConfigExist->set('status', TRUE);
        $blockConfigExist->set('region', $blockConfig['region']);
        $blockConfigExist->set('settings.label', $blockConfig['label']);
        $blockConfigExist->set('settings.label_display', $blockConfig['labelDisplay']);
        $blockConfigExist->set('visibility.request_path.id', 'request_path');
        $blockConfigExist->set('visibility.request_path.negate', FALSE);
        $blockConfigExist->set('visibility.request_path.pages', $blockConfig['displayPages']);
        $blockConfigExist->save();

      }else{

        $placed_block = Block::create([
          'id' => $blockConfig['blockMachineName'],
          'theme' => 'coderider',
          'weight' => $blockConfig['blockWeight'],
          'status' => TRUE,
          'region' => $blockConfig['region'],
          'plugin' => 'block_content:' . $blockConfig['uuid'],
          'settings' => [
            'label' => $blockConfig['label'],
            'label_display' => $blockConfig['labelDisplay']
          ],
          'visibility' => [
            'request_path' => [
              'id' => 'request_path',
              'negate' => FALSE,
              'pages' => $blockConfig['displayPages'],
            ],
          ],
        ]);

        $placed_block->save();
      }

    }
    return $this;
  }

  /**
   * In design we have to pages using layout builder to display blocks
   * create layout settings for those pages
   *
   * @return $this 
   */
  protected function createBasicPageLayout(){
    foreach ($this->basicPageLayoutFonfigs as $page => $configs) {
      $entity = Node::load($configs['node_id']);

      $sections[0] = new Section('layout_onecol',["label" => "Top Section"]);

      $topBlockOne = [
        'id' => "block_content:". $configs['topsection']['block_one_uuid'],
        'label_display' => 'visible',
        'provider' => 'block_content',
        'status' => 1,
        'view_mode' => 'default'
      ];
      $topBlockTwo = [
        'id' => "block_content:". $configs['topsection']['block_two_uuid'],
        'label_display' => 'visible',
        'provider' => 'block_content',
        'status' => 1,
        'view_mode' => 'default'
      ];
      $componentTop1 = new SectionComponent($configs['topsection']['block_one_uuid'], 'content', $topBlockOne);
      $componentTop2 = new SectionComponent($configs['topsection']['block_two_uuid'], 'content', $topBlockTwo);
      $sections[0]->appendComponent($componentTop1);
      $sections[0]->appendComponent($componentTop2);

      $sections[1] = new Section('layout_twocol_section',["label" => "Bottom Section", "column_widths" => "50-50"]);

      $bottomBlock1 = [
        'id' => "block_content:". $configs['bottom_section']['block_one_uuid'],
        'label_display' => 'visible',
        'provider' => 'block_content',
        'status' => 1,
        'view_mode' => 'default'
      ];
      $bottomBlock2 = [
        'id' => "block_content:" . $configs['bottom_section']['block_two_uuid'],
        'label_display' => 'visible',
        'provider' => 'block_content',
        'status' => 1,
        'view_mode' => 'default'
      ];

      $componentBottom1 = new SectionComponent($configs['bottom_section']['block_one_uuid'], 'first', $bottomBlock1);
      $componentBottom2 = new SectionComponent($configs['bottom_section']['block_two_uuid'], 'second', $bottomBlock2);
      $sections[1]->appendComponent($componentBottom1);
      $sections[1]->appendComponent($componentBottom2);
      
      $entity->layout_builder__layout->setValue($sections);
      $entity->save();
      
    }
    return $this;
  }

}
