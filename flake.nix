{
  description = "bug-free-happiness — PHP 8.5 + Node host toolchain (Composer, JS, lint/format/static-analysis)";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixpkgs-unstable";

    # fossar/nix-phps is the authoritative flake for pinned PHP versions in Nix;
    # it ships php85 ahead of nixpkgs-unstable. Pinning its nixpkgs to ours keeps
    # one nixpkgs in the closure and glibc consistent.
    phps = {
      url = "github:fossar/nix-phps";
      inputs.nixpkgs.follows = "nixpkgs";
    };
  };

  outputs = { self, nixpkgs, phps }:
    let
      supportedSystems = [
        "x86_64-linux"
        "aarch64-linux"
        "aarch64-darwin"
        "x86_64-darwin"
      ];
      forAllSystems = nixpkgs.lib.genAttrs supportedSystems;
    in
    {
      devShells = forAllSystems (system:
        let
          pkgs = nixpkgs.legacyPackages.${system};

          # ------------------------------------------------------------------
          # PHP 8.5 — Composer MUST come from the same instance so the loaded
          # extensions match. Extensions: Symfony core + pdo_pgsql (Postgres),
          # curl (HTTP/symfony-ai), pcntl/posix (messenger workers), opcache,
          # xdebug (off by default; enable with XDEBUG_MODE=debug).
          # ------------------------------------------------------------------
          php = phps.packages.${system}.php85.buildEnv {
            extensions = { enabled, all }: enabled ++ (with all; [
              ctype
              dom
              fileinfo
              iconv
              intl
              mbstring
              openssl
              simplexml
              tokenizer
              xmlwriter
              pdo_pgsql
              curl
              pcntl
              posix
              #opcache # built-in
              xdebug
            ]);

            extraConfig = ''
              memory_limit = -1
              opcache.enable = 0
              xdebug.mode = off
            '';
          };

          composer = php.packages.composer;

          # FrankenPHP is intentionally NOT provided — the app's real runtime is
          # the container (serversideup/php). The flake is the host toolchain:
          # PHP CLI + Composer + Node + linters for phpstan/php-cs-fixer/CI parity.
        in
        {
          default = pkgs.mkShellNoCC {
            name = "bug-free-happiness";

            packages = [
              php
              composer

              # JS toolchain (decoupled assistant-ui SPA — scaffolded in the
              # frontend slice; pin the exact Node line then).
              pkgs.nodejs_24
              pkgs.pnpm

              pkgs.git
              pkgs.gnumake

              # Meta-linters for non-PHP files (PHP tools come from vendor/bin).
              pkgs.shellcheck
              pkgs.shfmt
              pkgs.hadolint
              pkgs.dockerfmt
              pkgs.hclfmt
              pkgs.yamllint
              pkgs.markdownlint-cli2
            ];

            shellHook = ''
              echo "PHP:  $(php --version | head -1 | cut -d' ' -f1-2)"
              echo "Node: $(node --version)"
              echo
              echo "  make setup   # install git hooks"
              echo "  make install # composer install"
              echo "  make dev     # build local dev images"
              echo "  make up      # start the dev stack"
              echo "  make lint    # run linters"
            '';
          };
        }
      );
    };
}
