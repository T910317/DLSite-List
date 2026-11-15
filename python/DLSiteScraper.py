import asyncio
import argparse
import ipaddress
import json
import logging
import os
import re
import socket
import sys
from enum import Enum
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

import requests
from dlsite_async import DlsiteAPI
from dlsite_async.work import WorkOption, WorkType
from PIL import Image
from requests.adapters import HTTPAdapter

from weekly_logging import WeeklyFileHandler

ADDITIONAL_PRODUCT_FORMAT_OPTIONS = {
    WorkOption.VOICE,
    WorkOption.MUSIC,
    WorkOption.VIDEO,
}

MIRRORED_PRODUCT_FORMAT_OPTIONS = {
    WorkOption.VOICE: WorkType.VOICE_ASMR,
    WorkOption.MUSIC: WorkType.MUSIC,
    WorkOption.VIDEO: WorkType.VIDEO,
}

ALLOWED_IMAGE_HOSTS = frozenset({"img.dlsite.jp", "www.dlsite.com"})


class PinnedHTTPSAdapter(HTTPAdapter):
    def __init__(self, server_hostname):
        self.server_hostname = server_hostname
        super().__init__()

    def init_poolmanager(self, connections, maxsize, block=False, **pool_kwargs):
        pool_kwargs["assert_hostname"] = self.server_hostname
        pool_kwargs["server_hostname"] = self.server_hostname
        return super().init_poolmanager(
            connections,
            maxsize,
            block=block,
            **pool_kwargs,
        )


def parse_arguments(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--work-id", required=True)
    parser.add_argument("--json-output", required=True)
    parser.add_argument("--log-directory", required=True)
    parser.add_argument("--image-output")
    return parser.parse_args(argv)


def configure_logging(log_directory):
    logging.basicConfig(
        handlers=[
            WeeklyFileHandler(
                Path(log_directory),
                "DLSiteScraper",
                retention_days=os.getenv("LOG_RETENTION_DAYS"),
            )
        ],
        level=logging.DEBUG,
        format="%(asctime)s \n%(message)s",
        datefmt="[%Y-%m-%d] [%H:%M:%S]",
        force=True,
    )


def to_serializable(obj):
    if isinstance(obj, WorkOption):
        return obj.value
    if isinstance(obj, Enum):
        return {
            "_value_": to_serializable(obj.value),
            "_name_": obj.name,
        }
    if isinstance(obj, dict):
        return {k: to_serializable(v) for k, v in obj.items()}
    elif isinstance(obj, list):
        return [to_serializable(i) for i in obj]
    elif hasattr(obj, "model_dump"):
        return to_serializable(obj.model_dump())
    elif hasattr(obj, "__dict__"):
        return to_serializable(vars(obj))
    elif isinstance(obj, (str, int, float, bool)) or obj is None:
        return obj
    else:
        return str(obj)  # Fallback for anything weird


def product_format_codes(work):
    formats = []
    work_type = getattr(work, "work_type", None)

    if isinstance(work_type, WorkType):
        formats.append(work_type.value)

    for option in getattr(work, "options", None) or []:
        if (
            option in ADDITIONAL_PRODUCT_FORMAT_OPTIONS
            and MIRRORED_PRODUCT_FORMAT_OPTIONS[option] != work_type
            and option.value not in formats
        ):
            formats.append(option.value)

    return formats


async def japanese_dlsite(work_id):
    async with DlsiteAPI() as api:
        return await api.get_work(work_id)


async def english_dlsite(work_id):
    async with DlsiteAPI(locale="en_US") as api:
        return await api.get_work(work_id)


async def fetch_work_data(work_id):
    japanese = await japanese_dlsite(work_id)
    english = await english_dlsite(work_id)

    return japanese, english


def validated_image_target(url):
    if not isinstance(url, str):
        raise ValueError("Image URL must be a string")

    if url.startswith("//"):
        url = "https:" + url
    elif url.startswith("/"):
        url = "https://www.dlsite.com" + url

    parsed = urlsplit(url)

    try:
        port = parsed.port
    except ValueError as error:
        raise ValueError("Image URL has an invalid port") from error

    hostname = parsed.hostname
    if parsed.scheme != "https":
        raise ValueError("Image URL must use HTTPS")
    if parsed.username is not None or parsed.password is not None:
        raise ValueError("Image URL must not include credentials")
    if hostname not in ALLOWED_IMAGE_HOSTS:
        raise ValueError("Image URL host is not allowed")
    if port not in (None, 443):
        raise ValueError("Image URL must use port 443")

    addresses = []
    for family, _, _, _, socket_address in socket.getaddrinfo(
        hostname,
        443,
        type=socket.SOCK_STREAM,
    ):
        if family not in (socket.AF_INET, socket.AF_INET6):
            continue

        address = ipaddress.ip_address(socket_address[0].split("%", 1)[0])
        if not address.is_global:
            raise ValueError("Image URL resolved to a non-public address")

        canonical_address = str(address)
        if canonical_address not in addresses:
            addresses.append(canonical_address)

    if not addresses:
        raise ValueError("Image URL did not resolve to a public address")

    return parsed, hostname, addresses


def pinned_image_url(parsed, address):
    ip_address = ipaddress.ip_address(address)
    network_location = f"[{ip_address}]" if ip_address.version == 6 else str(ip_address)
    return urlunsplit(
        ("https", network_location, parsed.path or "/", parsed.query, "")
    )


def request_image(url, headers):
    parsed, hostname, addresses = validated_image_target(url)
    request_headers = {**headers, "Host": hostname}
    last_error = None

    for address in addresses:
        session = requests.Session()
        # A direct connection ensures the validated address is the actual peer.
        session.trust_env = False
        session.mount("https://", PinnedHTTPSAdapter(hostname))
        response = None

        try:
            response = session.get(
                pinned_image_url(parsed, address),
                stream=True,
                timeout=15,
                headers=request_headers,
                allow_redirects=False,
            )
            if 300 <= response.status_code < 400:
                raise requests.TooManyRedirects("Image redirects are not allowed")
            response.raise_for_status()
            return session, response
        except (requests.ConnectionError, requests.Timeout) as error:
            if response is not None:
                response.close()
            session.close()
            last_error = error
        except Exception:
            if response is not None:
                response.close()
            session.close()
            raise

    raise last_error


def download_image(url, save_path):
    headers = {
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/115.0.0.0 Safari/537.36"
        )
    }

    temporary_path = save_path.with_name(f"{save_path.name}.part")
    session = None
    response = None

    try:
        logging.debug(f"Downloading {url}")
        session, response = request_image(url, headers)

        save_path.parent.mkdir(parents=True, exist_ok=True)
        with open(temporary_path, "wb") as file:
            for chunk in response.iter_content(8192):
                file.write(chunk)

        if os.path.getsize(temporary_path) == 0:
            raise IOError("Downloaded file is empty")

        try:
            with Image.open(temporary_path) as image:
                image.verify()
            with Image.open(temporary_path) as image:
                image.load()
        except Exception as error:
            raise IOError(f"Corrupted or incomplete image: {error}")

        os.replace(temporary_path, save_path)
        logging.info(f"Downloaded {url} → {save_path}")
        return True
    except Exception as error:
        if temporary_path.exists():
            temporary_path.unlink()
        logging.warning(f"Download failed for {url}: {error}")
        return False
    finally:
        if response is not None:
            response.close()
        if session is not None:
            session.close()


def map_known_error(error_message: str, work_id: str):
    """
    Returns (user_message, exit_code) for known errors, else None.
    Keep user_message to ONE LINE so Laravel can display it cleanly.
    """
    msg = (error_message or "").strip()

    patterns = [
        (f"Failed to get product info for {work_id}", "GeoBlocked DLsite work", 2),
        (f"Not Found", "This work was deleted or could not be found on DLsite", 2),
        (f"Bad Request", "This work could not be found on DLsite", 2),
    ]

    for pattern, user_message, exit_code in patterns:
        if re.search(pattern, msg, flags=re.IGNORECASE):
            return user_message, exit_code

    return None


def fetch_images(work, image_output):
    downloaded_images = []
    failed_images = []
    image_output.mkdir(parents=True, exist_ok=True)
    image_targets = [
        ("cover.jpg", work.get("work_image")),
        *[
            (f"sample_{index}.jpg", url)
            for index, url in enumerate(work.get("sample_images") or [], start=1)
        ],
    ]

    for filename, url in image_targets:
        if url and download_image(url, image_output / filename):
            downloaded_images.append(filename)
        else:
            failed_images.append(filename)

    return downloaded_images, failed_images


def run(arguments):
    work_japanese, work_english = asyncio.run(fetch_work_data(arguments.work_id))
    japanese = to_serializable(work_japanese)
    english = to_serializable(work_english)
    japanese["product_format"] = product_format_codes(work_japanese)
    english["product_format"] = product_format_codes(work_english)
    combined = {"japanese": japanese, "english": english}
    json_output = Path(arguments.json_output)
    json_output.parent.mkdir(parents=True, exist_ok=True)

    with open(json_output, "w", encoding="utf-8") as f:
        json.dump(combined, f, ensure_ascii=False, indent=2)

    downloaded_images = []
    failed_images = []

    if arguments.image_output:
        downloaded_images, failed_images = fetch_images(
            japanese,
            Path(arguments.image_output),
        )

    product_id = japanese.get("product_id") or arguments.work_id
    logging.info(f"{product_id} completed")

    return {
        "product_id": product_id,
        "json_path": str(json_output),
        "downloaded_images": downloaded_images,
        "failed_images": failed_images,
    }


def main(argv=None):
    arguments = parse_arguments(argv)
    configure_logging(arguments.log_directory)

    try:
        print(json.dumps(run(arguments), ensure_ascii=False))
        return 0
    except Exception as error:
        error_message = str(error).strip()
        logging.error(f"Error occurred:\n{error}")
        mapped = map_known_error(error_message, arguments.work_id)

        if mapped:
            user_message, exit_code = mapped
            print(user_message, file=sys.stderr)
            return exit_code

        print(error_message, file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
