@tag:php, redis, vim
@category: default
@date:2014-06-01

在ubuntu中用apt-get安装LEMP栈(linux+nginx+mysql+php)
=============

在ubuntu上安装lamp大家应该都很熟悉了，但对于现在很流行的lemp栈怎么样用apt-get安装，这样介绍的文章的不多。下面我用**Ubuntu 12.04 LTS**为例来介绍下如何用`apt-get`安装这些。

为什么要用apt-get不用编译安装
------------------

用包管理除了可以方便统一的管理软件外，他还可以帮你搞定启动脚本，自动更新等一大堆麻烦的问题。其实大多数人用的编译安装，也是使用的默认编译参数，大多数定制化的东西都可以通过配置文件完成。如果你对编译的定制化比较高，甚至可以自己做一个私有源来放你自己编译的软件包。

准备工作
----

ubuntu安装以及一些常规的准备工作，我就不赘述了


<!--more-->


### 更新你的PHP源

ubuntu 12.04默认源里面的php版本比较旧，我的印象中貌似是5.3.9，现在5.6都快release了，很多新功能其实非常好用，所以我建议各位升级到5.5的最新版。我们需要添加一个私有源来安装最新的php，执行下面的命令

```bash
sudo add-apt-repository ppa:ondrej/php5
```

如果系统提示找不到`add-apt-repository`命令，你需要执行下列命令安装

```bash
sudo apt-get install python-software-properties
```

安装完以后再次执行上面的命令添加这个源，添加后别忘了

```bash
sudo apt-get update
```

### 添加Percona源

[Percona][1]是一个mysql非常著名的分支，由于现在的mysql已经被Oracle把持，所以很多非常有用的功能也故意没加进去，因此就出现了很多基于mysql的分支。其中Percona Server是最著名的一个，很多大公司都在使用，非常稳定，它与mysql协议完全兼容

首先增加一个`apt-key`

```bash
sudo apt-key adv --keyserver keys.gnupg.net --recv-keys 1C4CBDCDCD2EFD2A
```

然后编辑你的`/etc/apt/sources.list`文件，在最后加上这两个源(percise是ubuntu 12.04的代号，你可以根据自己的需求修改)

```
deb http://repo.percona.com/apt precise main
deb-src http://repo.percona.com/apt precise main
```

添加完以后别忘了

```bash
sudo apt-get update
```

好了，实际上你要做的所有的准备工作就是这么多了，用`apt-get`安装就是这么方便。

开始安装
----

下面的安装过程没有顺序要求

### 安装PHP

```bash
sudo apt-get install php5-fpm php5-cli php5-dev php5-mysql php5-curl php5-imagick
```

除了`php5-fpm php5-cli php5-dev`以外，其他的模块都是根据我的需求搭配的，你可以根据自己的需要自行删改。

### 安装Mysql(Percona Server)

如果你要安装mysql的话可以执行

```bash
sudo apt-get install mysql-server
```

不过我一般推荐安装Percona Server，使用上没有任何不同

```bash
sudo apt-get install percona-server-server-5.6
```

### 安装nginx

```bash
sudo apt-get install nginx
```

安装完成
----

现在所有的软件都已经安装上去了，你可以到'/etc'目录下找到这些软件的配置文件进行统一的配置。而且也可以使用`sudo apt-get upgrade`来进行更新了。


  [1]: http://www.percona.com/
