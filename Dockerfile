FROM caiotava/docker-php7-ncurses

RUN apt-get clean && apt-get update && apt-get install -y locales

COPY ./default_locale /etc/default/locale
RUN chmod 0755 /etc/default/locale

ENV LC_ALL=fr_FR.UTF-8
ENV LANG=fr_FR.UTF-8
ENV LANGUAGE=fr:en

RUN locale-gen fr_FR.UTF-8
