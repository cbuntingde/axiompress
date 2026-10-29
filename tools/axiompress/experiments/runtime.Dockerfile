FROM php@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e
RUN apk add --no-cache python3
# Context is an operator-created ignored cache containing checksum-verified release artifacts only.
ADD gvisor.tar.bz2 /usr/local/bin/
COPY worker.tar /artifact/worker.tar
WORKDIR /work
ENTRYPOINT ["/usr/local/bin/runsc"]
