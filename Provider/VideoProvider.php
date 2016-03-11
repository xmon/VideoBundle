<?php
namespace Maesbox\VideoBundle\Provider;

use Sonata\MediaBundle\Provider\BaseProvider;
use Sonata\MediaBundle\Entity\BaseMedia as Media;
use Sonata\MediaBundle\Model\MediaInterface;
use Sonata\MediaBundle\Resizer\ResizerInterface;

use Gaufrette\Adapter\Local;
use Sonata\CoreBundle\Model\Metadata;
use Sonata\MediaBundle\CDN\CDNInterface;
use Sonata\MediaBundle\Generator\GeneratorInterface;
use Sonata\MediaBundle\Thumbnail\ThumbnailInterface;
use Sonata\MediaBundle\Metadata\MetadataBuilderInterface;

use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Validator\ErrorElement;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Form\FormBuilder;


use Gaufrette\Filesystem;
use FFMpeg\FFProbe;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;

use GetId3\GetId3Core as GetId3;

use Symfony\Component\Form\Form;

class VideoProvider extends BaseProvider
{
    protected $allowedExtensions;

    protected $allowedMimeTypes;

    protected $metadata;

    protected $getId3;

    protected $ffprobe;

    protected $ffmpeg;

    /**
     * @param string $name
     * @param Filesystem $filesystem
     * @param CDNInterface $cdn
     * @param GeneratorInterface $pathGenerator
     * @param ThumbnailInterface $thumbnail
     * @param array $allowedExtensions
     * @param array $allowedMimeTypes
     * @param ResizerInterface $resizer
     * @param MetadataBuilderInterface|null $metadata
     * @param FFMpeg $FFMpeg
     * @param FFProbe $FFProbe
     */
    public function __construct($name, Filesystem $filesystem, CDNInterface $cdn, GeneratorInterface $pathGenerator, ThumbnailInterface $thumbnail, array $allowedExtensions = array(), array $allowedMimeTypes = array(), ResizerInterface $resizer, MetadataBuilderInterface $metadata = null, FFMpeg $FFMpeg, FFProbe $FFProbe )
    {
        parent::__construct($name, $filesystem, $cdn, $pathGenerator, $thumbnail, null, $metadata);

        $this->allowedExtensions = $allowedExtensions;
        $this->allowedMimeTypes = $allowedMimeTypes;
        $this->metadata = $metadata;
        $this->resizer = $resizer;
        $this->getId3 = new GetId3;
        $this->ffprobe = $FFProbe;
        $this->ffmpeg = $FFMpeg;
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderMetadata()
    {
        return new Metadata($this->getName(), $this->getName().".description", null, "SonataMediaBundle", array('class' => 'fa fa-video'));
    }

    protected function doTransform(MediaInterface $media)
    {
        $this->fixBinaryContent($media);
        $this->fixFilename($media);

        if (!is_object($media->getBinaryContent()) && !$media->getBinaryContent()) {
            return;
        }

        $stream = $this->ffprobe
                        ->streams($media->getBinaryContent()->getRealPath())
                        ->videos()
                        ->first();

        $framecount = $stream->get('nb_frames');
        $duration = $stream->get('duration');
        $height = $stream->get('height');
        $width = $stream->get('width');

        $video = $this->ffmpeg->open($media->getBinaryContent()->getRealPath());

        if (!$media->getProviderReference()) {
            $media->setProviderReference($this->generateReferenceName($media));
        }

        $frame_pos = round($framecount / 2);
        $timecode = new Timecode("0", "0", "0", $frame_pos);
        $frame = $video->frame($timecode);
        while(!$frame && $frame_pos > 0){
            $frame_pos--;
            $timecodeString = sprintf("0:0:0:0.%d", $frame_pos);
            $timecode = new Timecode("0", "0", "0", $frame_pos);
            $frame = $video->frame($timecode);
        }

        if(!$frame){
            echo "Thumbnail Generation Failed"; exit;
        }

        if ($media->getBinaryContent()) {
            $media->setContentType($media->getBinaryContent()->getMimeType());
            $media->setSize($media->getBinaryContent()->getSize());
            $media->setWidth($width);
            $media->setHeight($height);
            $media->setLength($duration);
        }

        $media->setProviderName($this->name);
        $media->setProviderStatus(MediaInterface::STATUS_OK);
    }

    public function buildCreateForm(FormMapper $formMapper)
    {
        $formMapper->add('binaryContent', 'file');
    }

    public function buildEditForm(FormMapper $formMapper)
    {
        $formMapper->add('name');
        $formMapper->add('enabled', null, array('required' => false));
        $formMapper->add('authorName');
        $formMapper->add('cdnIsFlushable');
        $formMapper->add('description');
        $formMapper->add('copyright');
        $formMapper->add('binaryContent', 'file', array('required' => false));
    }

    public function buildMediaType(FormBuilder $formBuilder)
    {
        $formBuilder->add('binaryContent', 'file');
    }

    public function generateThumbnails(MediaInterface $media, $ext = 'jpeg')
    {
        echo "Video wird verarbeitet. Einen Moment bitte..."; 
        flush();

        //convert video
        $source =  sprintf('%s/%s/%s',
            $this->getFilesystem()->getAdapter()->getDirectory(),
            $this->generatePath($media),
            $media->getProviderReference());

        $path = sprintf('%s/%s/videos_mp4_%s',
            $this->getFilesystem()->getAdapter()->getDirectory(),
            $this->generatePath($media),
            $media->getProviderReference());

        $path2 = sprintf('%s/%s/videos_ogg_%s',
            $this->getFilesystem()->getAdapter()->getDirectory(),
            $this->generatePath($media),
            $media->getProviderReference());


        $path = preg_replace('/\.[^.]+$/', '.' . 'mp4', $path);
        $ogg = preg_replace('/\.[^.]+$/', '.' . 'ogv', $path2);

        $height = round(640 * $media->getHeight() / $media->getWidth());

        // ffmpeg -i /var/www/vhosts/nordfabrik.com/uniscene-portal/web/uploads/media/social/0001/02/528f56aad4b570ca55182652dcccedf0b08147a5.mp4 -vcodec libx264 -acodec libmp3lame -s 640x360 -b:a 128K -b:v 896K -strict experimental -y /var/www/vhosts/nordfabrik.com/uniscene-portal/web/uploads/media/social/0001/02/videos_mp4_528f56aad4b570ca55182652dcccedf0b08147a5.mp4
        $ffcmd = "ffmpeg -i $source -vcodec libx264 -acodec libmp3lame -s 640x$height -b:a 128K -b:v 896K -strict experimental -y $path";
        #$bxcmd = "MP4Box -inter 200 $source" ;
        $ffogg = "ffmpeg -i $source -vcodec libtheora -acodec libvorbis -s 640x$height -b:a 128K -b:v 896K -strict experimental -y $ogg";

        $output = array();
        $return = 0;
        exec("$ffcmd", $output, $return);
        #exec("$bxcmd", $output, $return);
        exec("$ffogg", $output, $return);


        $this->generateReferenceImage($media);

        //parent::generateThumbnails($media);

        if (!$this->requireThumbnails()) {
            return;
        }

        $referenceImage = $this->getReferenceImage($media);

        foreach ($this->getFormats() as $format => $settings) {
            if (substr($format, 0, strlen($media->getContext())) == $media->getContext() || $format === 'admin') {
                $this->getResizer()->resize(
                    $media,
                    $referenceImage,
                    $this->getFilesystem()->get($this->generateThumbsPrivateUrl($media, $format, $ext), true),
                    $ext,
                    $settings
                );
            }
        }
    }


    public function generateThumbsPrivateUrl($media, $format, $ext = 'jpeg')
    {
        return sprintf('%s/thumb_%s_%s.%s',
            $this->generatePath($media),
            $media->getId(),
            $format,
            $ext
        );
    }

    /**
    * {@inheritdoc}
    */
    public function generatePrivateUrl(MediaInterface $media, $format)
    {

    }

    /**
     * {@inheritdoc}
     */
    public function getFormatName(MediaInterface $media, $format)
    {
        if ($format == 'admin') {
            return 'admin';
        }

        if ($format == 'reference') {
            return 'reference';
        }

        return $format;

        $baseName = $media->getContext().'_';
        if (substr($format, 0, strlen($baseName)) == $baseName) {
            return $format;
        }

        return $baseName.$format;
    }

    public function generatePublicUrl(MediaInterface $media, $format)
    {
        if ($format == 'reference')
        {
            $path = sprintf('%s/%s', $this->generatePath($media), $media->getProviderReference());
        }
        elseif ($format == 'admin')
        {
            $path = sprintf('%s/%s', $this->generatePath($media), str_replace($this->getExtension($media), 'jpeg', $media->getProviderReference()));
        }
        elseif ($format == 'videos_ogv')
        {
            $path = sprintf('%s/%s_%s', $this->generatePath($media), $format, str_replace($media->getExtension(), 'ogv', $media->getProviderReference()));
        }
        elseif ($format == 'videos_mp4')
        {
            $path = sprintf('%s/%s_%s', $this->generatePath($media), $format, str_replace($media->getExtension(), 'mp4', $media->getProviderReference()));
        }
        else
        {
            $path = sprintf('%s/%s', $this->generatePath($media), str_replace($this->getExtension($media), 'jpeg', $media->getProviderReference()));
        }
        /*else
        {
            $path = sprintf('%s/%s_%s', $this->generatePath($media), $format, $media->getProviderReference());
        }*/

        //$path = sprintf('%s/%s', $this->generatePath($media), $media->getProviderReference());
        return $this->getCdn()->getPath($path, $media->getCdnIsFlushable());
        //return ;
    }

    public function getDownloadResponse(MediaInterface $media, $format, $mode, array $headers = array())
    {
        // build the default headers
        $headers = array_merge(array(
            'Content-Type' => $media->getContentType(),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $media->getMetadataValue('filename')),
        ), $headers);

        if (!in_array($mode, array('http', 'X-Sendfile', 'X-Accel-Redirect'))) {
            throw new \RuntimeException('Invalid mode provided');
        }

        if ($mode == 'http') {
            $provider = $this;

            return new StreamedResponse(function() use ($provider, $media, $format) {
                if($format == 'reference') {
                    echo $provider->getReferenceFile($media)->getContent();
                } else {
                    echo $provider->getFilesystem()->get($provider->generatePrivateUrl($media, $format))->getContent();
                }
            }, 200, $headers);
        }

        if (!$this->getFilesystem()->getAdapter() instanceof \Sonata\MediaBundle\Filesystem\Local) {
            throw new \RuntimeException('Cannot use X-Sendfile or X-Accel-Redirect with non \Sonata\MediaBundle\Filesystem\Local');
        }

        $headers[$mode] = sprintf('%s/%s',
            $this->getFilesystem()->getAdapter()->getDirectory(),
            $this->generatePrivateUrl($media, $format)
        );

        return new Response('', 200, $headers);
    }

    /**
    * {@inheritdoc}
    */
    public function getHelperProperties(MediaInterface $media, $format, $options = array())
    {
        $box = $this->getBoxHelperProperties($media, $format, $options);
        return array_merge(array(
            'id' => key_exists("id", $options) ? $options["id"] : $media->getId(),
            'title' => $media->getName(),
            'thumbnail' => $this->getReferenceImage($media),
            'file' => $this->generatePublicUrl($media, $format),
            'realref' => $media->getProviderReference(),
            'width'             => $box->getWidth(),
            'height'            => $box->getHeight(),
            'duration' => $media->getLength(),
        ), $options);
    }

    public function getReferenceFile(MediaInterface $media)
    {
        return $this->getFilesystem()->get(sprintf('%s/%s',$this->generatePath($media),$media->getProviderReference()), true);
    }

    public function getReferencePath(MediaInterface $media, $format)
    {
        return $this->getFilesystem()->get(sprintf('%s/%s_%s',$this->generatePath($media), $format, $media->getProviderReference()), true);
    }

    public function getReferenceImage(MediaInterface $media)
    {
        return $this->getFilesystem()->get(sprintf('%s/%s', $this->generatePath($media),str_replace($this->getExtension($media), 'jpeg', $media->getProviderReference())), true);
    }

    public function generateReferenceImage(MediaInterface $media)
    {
        #$this->fixBinaryContent($media);
        #$this->setFileContents($media);
        $path = sprintf(
            '%s/%s/%s',
            $this->getFilesystem()->getAdapter()->getDirectory(),
            $this->generatePath($media),
            $media->getProviderReference()
        );

        $stream = $this->ffprobe
                        ->streams($path)
                        ->videos()
                        ->first();

        $framecount = $stream->get('nb_frames');
        $duration = $stream->get('duration');
        $height = $stream->get('height');
        $width = $stream->get('width');

        $video = $this->ffmpeg->open($path);

        if (!$media->getProviderReference()) {
            $media->setProviderReference($this->generateReferenceName($media));
        }



        #$frame_pos = round($fileinfos->getFrameCount() / 2);
        #$frame = $fileinfos->getFrame($frame_pos);

        //Get number of images per second
        $img_par_s=$framecount/$duration;

        // Récupère l'image
        $frame_pos = round(15*$img_par_s);

        $timecode = new Timecode("0", "0", "0", $frame_pos);
        $frame = $video->frame($timecode);

        while(!$frame && $frame_pos > 0)
        {
            $frame_pos--;
            $frame = $video->frame($frame_pos);
        }

        if(!$frame)
        {
            echo "Thumbnail Generation Failed"; exit;
        }

        $thumnailPath = sprintf(
            '%s/%s/%s',
            $this->getFilesystem()->getAdapter()->getDirectory(),
            $this->generatePath($media),
            str_replace(
                $this->getExtension($media),
                'jpeg',
                $media->getProviderReference()
            )
        );

        $frame->save($thumnailPath);
    }

    public function postPersist(MediaInterface $media)
    {
        if ($media->getBinaryContent() === null) {
            return;
        }

        $this->setFileContents($media);

        $this->generateThumbnails($media);
    }

    public function postRemove(MediaInterface $media) {

    }

    public function postUpdate(MediaInterface $media)
    {
         if (!$media->getBinaryContent() instanceof \SplFileInfo) {
            return;
        }

        // Delete the current file from the FS
        $oldMedia = clone $media;
        $oldMedia->setProviderReference($media->getPreviousProviderReference());

        if ($this->getFilesystem()->has($oldMedia)) {
            $this->getFilesystem()->delete($oldMedia);
        }

        $this->fixBinaryContent($media);

        $this->setFileContents($media);

        $this->generateThumbnails($media);
    }

    public function updateMetadata(MediaInterface $media, $force = false)
    {
        $file = sprintf('%s/%s/%s',$this->getFilesystem()->getAdapter()->getDirectory(), $this->generatePath($media),$media->getProviderReference());
        $fileinfos = new ffmpeg_movie($file, false);

        $img_par_s = $fileinfos->getFrameCount()/$fileinfos->getDuration();

        // Récupère l'image
        $frame = $fileinfos->getFrame(15*$img_par_s);

        //$media->setContentType($media->getProviderReference()->getMimeType());
        $media->setContentType(mime_content_type($file));
        $media->setSize(filesize($file));

        $media->setWidth($frame->getWidth());
        $media->setHeight($frame->getHeight());
        $media->setLength($fileinfos->getDuration());

        $media->setMetadataValue('bitrate', $fileinfos->getBitRate());

    }

    /**
    * @throws \RuntimeException
    *
    * @param \Sonata\MediaBundle\Model\MediaInterface $media
    *
    * @return
    */
    protected function fixBinaryContent(MediaInterface $media)
    {
        if ($media->getBinaryContent() === null) {
            return;
        }

        // if the binary content is a filename => convert to a valid File
        if (!$media->getBinaryContent() instanceof File) {
            if (!is_file($media->getBinaryContent())) {
                throw new \RuntimeException('The file does not exist : ' . $media->getBinaryContent());
            }

            $binaryContent = new File($media->getBinaryContent());

            $media->setBinaryContent($binaryContent);
        }
    }

    /**
    * @throws \RuntimeException
    *
    * @param \Sonata\MediaBundle\Model\MediaInterface $media
    *
    * @return void
    */
    protected function fixFilename(MediaInterface $media)
    {
        if ($media->getBinaryContent() instanceof UploadedFile) {
            $media->setName($media->getName() ?: $media->getBinaryContent()->getClientOriginalName());
            $media->setMetadataValue('filename', $media->getBinaryContent()->getClientOriginalName());
        } elseif ($media->getBinaryContent() instanceof File) {
            $media->setName($media->getName() ?: $media->getBinaryContent()->getBasename());
            $media->setMetadataValue('filename', $media->getBinaryContent()->getBasename());
        }

        // this is the original name
        if (!$media->getName()) {
            throw new \RuntimeException('Please define a valid media\'s name');
        }
    }

    /**
    * @param \Sonata\MediaBundle\Model\MediaInterface $media
    *
    * @return string
    */
    protected function generateReferenceName(MediaInterface $media)
    {
        return sha1($media->getName() . rand(11111, 99999)) . '.' . $media->getBinaryContent()->guessExtension();
    }

    /**
    * Set the file contents for a video
    *
    * @param \Sonata\MediaBundle\Model\MediaInterface $media
    * @param string $contents path to contents, defaults to MediaInterface BinaryContent
    *
    * @return void
    */
    protected function setFileContents(MediaInterface $media, $contents = null)
    {
        if (!$contents)
        {
            $contents = $media->getBinaryContent()->getRealPath();
        }

        $destination = sprintf('%s/%s/',$this->getFilesystem()->getAdapter()->getDirectory(), $this->generatePath($media));

        if(!is_dir($destination))
        {
            mkdir($destination, 775, true);
        }

        if(is_uploaded_file($contents) )
        {
            move_uploaded_file($contents,$destination.$media->getProviderReference());
        }
        else
        {
            copy($contents,$destination.$media->getProviderReference());
        }

    }

    /**
     * @param \Sonata\MediaBundle\Model\MediaInterface $media
     * @param string                                   $format
     * @param array                                    $options
     *
     * @return \Imagine\Image\Box
     */
    protected function getBoxHelperProperties(MediaInterface $media, $format, $options = array())
    {
        if ($format == 'reference')
        {
            return $media->getBox();
        }

        if (isset($options['width']) || isset($options['height']))
        {
            $settings = array(
                'width'  => isset($options['width']) ? $options['width'] : null,
                'height' => isset($options['height']) ? $options['height'] : null,
            );

        }
        else
        {
            $settings = $this->getFormat($format);
        }

        return $this->resizer->getBox($media, $settings);
    }

    /**
     * @param \Sonata\MediaBundle\Model\MediaInterface $media
     *
     * @return string the file extension for the $media, or the $defaultExtension if not available
     */
    protected function getExtension(MediaInterface $media)
    {
        $ext = $media->getExtension();
        if (!is_string($ext) || strlen($ext) < 2) {
            $ext = "mp4";
        }
        return $ext;
    }

    public function setLogger($logger) {
        $this->logger = $logger;
    }
}
