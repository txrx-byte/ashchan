"""CLI entry-point for the 4chan harvester."""

from __future__ import annotations

import logging
import sys

import click
from rich.console import Console
from rich.logging import RichHandler
from rich.table import Table

from .config import HarvesterConfig, DatabaseConfig, S3Config, FourChanConfig
from .harvester import Harvester

console = Console()


def _setup_logging(verbose: bool) -> None:
    level = logging.DEBUG if verbose else logging.INFO
    logging.basicConfig(
        level=level,
        format="%(message)s",
        datefmt="[%X]",
        handlers=[RichHandler(rich_tracebacks=True, console=console)],
    )
    # Suppress noisy libraries
    logging.getLogger("httpx").setLevel(logging.WARNING)
    logging.getLogger("httpcore").setLevel(logging.WARNING)
    logging.getLogger("boto3").setLevel(logging.WARNING)
    logging.getLogger("botocore").setLevel(logging.WARNING)
    logging.getLogger("urllib3").setLevel(logging.WARNING)
    logging.getLogger("s3transfer").setLevel(logging.WARNING)


def _print_stats(stats: dict) -> None:
    table = Table(title="Harvest Summary", show_header=True, header_style="bold cyan")
    table.add_column("Metric", style="bold")
    table.add_column("Count", justify="right")
    for key, val in stats.items():
        table.add_row(key.capitalize(), str(val))
    console.print(table)


@click.group()
@click.option("--db-host", envvar="DB_HOST", default="localhost", help="PostgreSQL host")
@click.option("--db-port", envvar="DB_PORT", default=5432, type=int, help="PostgreSQL port")
@click.option("--db-name", envvar="DB_NAME", default="ashchan", help="PostgreSQL database name")
@click.option("--db-user", envvar="DB_USER", default="ashchan", help="PostgreSQL user")
@click.option("--db-password", envvar="DB_PASSWORD", default="ashchan", help="PostgreSQL password")
@click.option("--s3-endpoint", envvar="S3_ENDPOINT", default="http://localhost:9000", help="MinIO/S3 endpoint URL")
@click.option("--s3-access-key", envvar="S3_ACCESS_KEY", default="minioadmin", help="S3 access key")
@click.option("--s3-secret-key", envvar="S3_SECRET_KEY", default="minioadmin", help="S3 secret key")
@click.option("--s3-bucket", envvar="S3_BUCKET", default="ashchan", help="S3 bucket name")
@click.option("-v", "--verbose", is_flag=True, help="Enable debug logging")
@click.pass_context
def cli(ctx: click.Context, **kwargs: object) -> None:
    """4chan Harvester – Import 4chan data into Ashchan.

    Fetches threads, posts, and images from the 4chan API and stores
    them in the Ashchan database and MinIO object storage.
    """
    _setup_logging(bool(kwargs.pop("verbose")))
    ctx.ensure_object(dict)
    ctx.obj["db_cfg"] = DatabaseConfig(
        host=kwargs["db_host"],  # type: ignore[arg-type]
        port=kwargs["db_port"],  # type: ignore[arg-type]
        dbname=kwargs["db_name"],  # type: ignore[arg-type]
        user=kwargs["db_user"],  # type: ignore[arg-type]
        password=kwargs["db_password"],  # type: ignore[arg-type]
    )
    ctx.obj["s3_cfg"] = S3Config(
        endpoint=kwargs["s3_endpoint"],  # type: ignore[arg-type]
        access_key=kwargs["s3_access_key"],  # type: ignore[arg-type]
        secret_key=kwargs["s3_secret_key"],  # type: ignore[arg-type]
        bucket=kwargs["s3_bucket"],  # type: ignore[arg-type]
    )


def _make_config(ctx: click.Context, *, images: bool = True, thumbs: bool = True, dry_run: bool = False) -> HarvesterConfig:
    return HarvesterConfig(
        db=ctx.obj["db_cfg"],
        s3=ctx.obj["s3_cfg"],
        download_images=images,
        generate_thumbnails=thumbs,
        dry_run=dry_run,
    )


# ─── Commands ────────────────────────────────────────────────────


@cli.command()
@click.argument("board")
@click.argument("thread_no", type=int)
@click.option("--no-images", is_flag=True, help="Skip image downloads")
@click.option("--no-thumbs", is_flag=True, help="Skip thumbnail generation")
@click.option("--dry-run", is_flag=True, help="Fetch & display data without writing to DB")
@click.pass_context
def thread(ctx: click.Context, board: str, thread_no: int, no_images: bool, no_thumbs: bool, dry_run: bool) -> None:
    """Harvest a single thread.

    Example: harvester thread g 12345678
    """
    cfg = _make_config(ctx, images=not no_images, thumbs=not no_thumbs, dry_run=dry_run)
    with Harvester(cfg) as h:
        console.print(f"[bold]Harvesting [cyan]/{board}/{thread_no}[/cyan]...[/bold]")
        ok = h.harvest_thread(board, thread_no)
        if ok:
            console.print(f"[green]✓[/green] Thread /{board}/{thread_no} imported successfully")
        else:
            console.print(f"[red]✗[/red] Thread /{board}/{thread_no} not found or empty")
            sys.exit(1)
        _print_stats(h.stats)


@cli.command()
@click.argument("board")
@click.option("--no-images", is_flag=True, help="Skip image downloads")
@click.option("--dry-run", is_flag=True, help="Fetch & display data without writing to DB")
@click.pass_context
def catalog(ctx: click.Context, board: str, no_images: bool, dry_run: bool) -> None:
    """Harvest the catalog for a board (OPs only).

    Example: harvester catalog g
    """
    cfg = _make_config(ctx, images=not no_images, dry_run=dry_run)
    with Harvester(cfg) as h:
        console.print(f"[bold]Harvesting catalog for [cyan]/{board}/[/cyan]...[/bold]")
        count = h.harvest_catalog(board)
        console.print(f"[green]✓[/green] Imported {count} threads from /{board}/ catalog")
        _print_stats(h.stats)


@cli.command()
@click.argument("board")
@click.option("--archive/--no-archive", default=False, help="Include archived threads")
@click.option("--limit", default=0, type=int, help="Max threads to harvest (0 = all)")
@click.option("--no-images", is_flag=True, help="Skip image downloads")
@click.option("--no-thumbs", is_flag=True, help="Skip thumbnail generation")
@click.option("--dry-run", is_flag=True, help="Fetch & display data without writing to DB")
@click.pass_context
def board(ctx: click.Context, board: str, archive: bool, limit: int, no_images: bool, no_thumbs: bool, dry_run: bool) -> None:
    """Harvest an entire board (all threads + full content).

    Example: harvester board g --limit 10
    """
    cfg = _make_config(ctx, images=not no_images, thumbs=not no_thumbs, dry_run=dry_run)
    with Harvester(cfg) as h:
        console.print(f"[bold]Harvesting board [cyan]/{board}/[/cyan]...[/bold]")
        count = h.harvest_board(board, include_archive=archive, limit=limit)
        console.print(f"[green]✓[/green] Imported {count} threads from /{board}/")
        _print_stats(h.stats)


@cli.command()
@click.argument("boards", nargs=-1, required=True)
@click.option("--archive/--no-archive", default=False, help="Include archived threads")
@click.option("--limit", default=0, type=int, help="Max threads per board (0 = all)")
@click.option("--no-images", is_flag=True, help="Skip image downloads")
@click.option("--no-thumbs", is_flag=True, help="Skip thumbnail generation")
@click.pass_context
def multi(ctx: click.Context, boards: tuple[str, ...], archive: bool, limit: int, no_images: bool, no_thumbs: bool) -> None:
    """Harvest multiple boards.

    Example: harvester multi g a v --limit 5
    """
    cfg = _make_config(ctx, images=not no_images, thumbs=not no_thumbs)
    with Harvester(cfg) as h:
        console.print(f"[bold]Harvesting {len(boards)} boards: {', '.join(f'/{b}/' for b in boards)}[/bold]")
        results = h.harvest_boards(list(boards), include_archive=archive, limit=limit)
        for slug, count in results.items():
            console.print(f"  /{slug}/: {count} threads")
        _print_stats(h.stats)


@cli.command(name="list-boards")
@click.pass_context
def list_boards(ctx: click.Context) -> None:
    """List all available 4chan boards."""
    from .api import FourChanAPI

    with FourChanAPI() as api:
        boards = api.get_boards()
        table = Table(title="4chan Boards", show_header=True, header_style="bold cyan")
        table.add_column("Board", style="bold")
        table.add_column("Title")
        table.add_column("SFW", justify="center")
        for b in sorted(boards, key=lambda x: x.get("board", "")):
            sfw = "✓" if b.get("ws_board", 0) else "✗"
            table.add_row(f"/{b['board']}/", b.get("title", ""), sfw)
        console.print(table)


@cli.command(name="preview")
@click.argument("board")
@click.option("--limit", default=10, type=int, help="Number of threads to show")
@click.pass_context
def preview(ctx: click.Context, board: str, limit: int) -> None:
    """Preview a board's catalog without importing.

    Example: harvester preview g --limit 5
    """
    from .api import FourChanAPI

    with FourChanAPI() as api:
        catalog_data = api.get_catalog(board)
        table = Table(title=f"/{board}/ Catalog Preview", show_header=True, header_style="bold cyan")
        table.add_column("No", style="bold", justify="right")
        table.add_column("Subject", max_width=40)
        table.add_column("Replies", justify="right")
        table.add_column("Images", justify="right")
        table.add_column("Has File", justify="center")

        count = 0
        for page in catalog_data:
            for t in page.get("threads", []):
                if count >= limit:
                    break
                sub = (t.get("sub") or t.get("com", ""))[:40]
                has_file = "✓" if t.get("tim") else ""
                table.add_row(
                    str(t["no"]),
                    sub,
                    str(t.get("replies", 0)),
                    str(t.get("images", 0)),
                    has_file,
                )
                count += 1
            if count >= limit:
                break
        console.print(table)


def main() -> None:
    cli()


if __name__ == "__main__":
    main()
