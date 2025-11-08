FROM debian:bookworm-slim

# Install build dependencies
RUN apt-get update && apt-get install -y \
    git \
    build-essential \
    libtool \
    autotools-dev \
    automake \
    pkg-config \
    liblmdb-dev \
    libsecp256k1-dev \
    libzstd-dev \
    libssl-dev \
    wget \
    && rm -rf /var/lib/apt/lists/*

# Build strfry from source
WORKDIR /tmp
RUN git clone --depth 1 --branch v1.0.6 https://github.com/hoytech/strfry.git && \
    cd strfry && \
    git submodule update --init && \
    make setup-golpe && \
    make -j$(nproc) && \
    make install && \
    cd / && \
    rm -rf /tmp/strfry

# Create data directory
RUN mkdir -p /var/strfry/db

# Expose relay port
EXPOSE 7777

# Default command (can be overridden)
CMD ["strfry", "relay"]

