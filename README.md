# VideoBundle
This project is abandoned, a new project to continue this functionality is created.

[NEW PROJECT SonataMediaProviderVideoBundle](https://github.com/xmon/SonataMediaProviderVideoBundle)

## Installation and configuration:

### First you need install phansys/getid3 dependency
```sh
$ php composer.phar require phansys/getid3:~2.1@dev
```
There are a problem installing from composer.json of this bundle, [issue #16](https://github.com/phansys/GetId3/issues/16)

### Install this bundle
```sh
$ php composer.phar require xmon/video-bundle 
```

## Add VideoBundle to your application kernel
```php
// app/AppKernel.php
public function registerBundles()
{
    return array(
        // ...
        new Maesbox\VideoBundle\MaesboxVideoBundle(),
        // ...
    );
}
```

## Configuration example

You can configure default parameters

```yaml
maesbox_video:
    ffmpeg_binary: "/usr/local/bin/ffmpeg" # Required, ffmpeg binary path
    ffprobe_binary: "/usr/local/bin/ffprobe" # Required, ffprobe binary path
    binary_timeout: 60 # Optional, default 60
    threads_count: 4 # Optional, default 4
    config:
        image_frame: 10 # Optional, default 10, Can not be empty, second from extract image
        mp4: true #default true, generate MP4 format
        ogg: true #default true, generate OGG format
        webm: true #default true, generate WEBM format
```