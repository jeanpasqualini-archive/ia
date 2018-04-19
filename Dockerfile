FROM ubuntu:14.04

RUN apt-get update
RUN apt-get install -y php5-dev
RUN apt-get install -y ncurses-dev
RUN apt-get install -y libncursesw5-dev
RUN apt-get install -y php-pear
RUN apt-get install -y build-essential
RUN pecl install ncurses

RUN locale-gen en_US.UTF-8
ENV LANG en_US.UTF-8
ENV LANGUAGE en_US:en
ENV LC_ALL en_US.UTF-8
