# VideoBundle
For install purpose

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
    ffmpeg_binary: ""
    ffprobe_binary: ""
```