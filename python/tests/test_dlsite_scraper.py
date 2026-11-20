import socket
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import MagicMock, patch
from urllib.parse import urlsplit

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import requests
from dlsite_async.work import AgeCategory, BookType, WorkOption, WorkType
from DLSiteScraper import (
    product_format_codes,
    request_image,
    to_serializable,
    validated_image_target,
)


class DLSiteScraperImageDownloadSecurityTest(unittest.TestCase):
    public_dns_result = [
        (socket.AF_INET, socket.SOCK_STREAM, 6, "", ("93.184.216.34", 443)),
    ]

    def test_known_dlsite_image_url_forms_remain_allowed(self):
        with patch(
            "DLSiteScraper.socket.getaddrinfo",
            return_value=self.public_dns_result,
        ):
            protocol_relative, hostname, addresses = validated_image_target(
                "//img.dlsite.jp/modpub/example.jpg"
            )
            root_relative, root_hostname, root_addresses = validated_image_target(
                "/modpub/example.jpg"
            )
            mixed_case, mixed_hostname, mixed_addresses = validated_image_target(
                "HTTPS://IMG.DLSITE.JP/modpub/example.jpg"
            )

        self.assertEqual("https", protocol_relative.scheme)
        self.assertEqual("img.dlsite.jp", hostname)
        self.assertEqual(["93.184.216.34"], addresses)
        self.assertEqual("https", root_relative.scheme)
        self.assertEqual("www.dlsite.com", root_hostname)
        self.assertEqual(["93.184.216.34"], root_addresses)
        self.assertEqual("https", mixed_case.scheme)
        self.assertEqual("img.dlsite.jp", mixed_hostname)
        self.assertEqual(["93.184.216.34"], mixed_addresses)

    def test_untrusted_image_url_forms_are_rejected_before_dns(self):
        unsafe_urls = [
            "http://img.dlsite.jp/example.jpg",
            "file:///etc/passwd",
            "gopher://img.dlsite.jp/example.jpg",
            "https://localhost/example.jpg",
            "https://127.0.0.1/example.jpg",
            "https://169.254.169.254/latest/meta-data/",
            "https://user@img.dlsite.jp/example.jpg",
            "https://img.dlsite.jp:8443/example.jpg",
            "https://img.dlsite.jp.example.com/example.jpg",
        ]

        with patch("DLSiteScraper.socket.getaddrinfo") as resolver:
            for url in unsafe_urls:
                with self.subTest(url=url), self.assertRaises(ValueError):
                    validated_image_target(url)

        resolver.assert_not_called()

    def test_every_resolved_address_must_be_public(self):
        unsafe_addresses = [
            "10.0.0.1",
            "127.0.0.1",
            "169.254.169.254",
            "224.0.0.1",
            "240.0.0.1",
            "::1",
            "fe80::1",
        ]

        for address in unsafe_addresses:
            family = socket.AF_INET6 if ":" in address else socket.AF_INET
            dns_results = [
                *self.public_dns_result,
                (family, socket.SOCK_STREAM, 6, "", (address, 443)),
            ]

            with (
                self.subTest(address=address),
                patch("DLSiteScraper.socket.getaddrinfo", return_value=dns_results),
                self.assertRaisesRegex(ValueError, "non-public"),
            ):
                validated_image_target("https://img.dlsite.jp/example.jpg")

    def test_request_pins_dns_result_and_disables_redirects(self):
        response = MagicMock(status_code=200)
        session = MagicMock()
        session.get.return_value = response

        with (
            patch(
                "DLSiteScraper.validated_image_target",
                return_value=(
                    urlsplit("https://img.dlsite.jp/example.jpg?size=large"),
                    "img.dlsite.jp",
                    ["93.184.216.34"],
                ),
            ),
            patch("DLSiteScraper.requests.Session", return_value=session),
        ):
            returned_session, returned_response = request_image(
                "https://img.dlsite.jp/example.jpg?size=large",
                {"User-Agent": "test"},
            )

        self.assertIs(returned_session, session)
        self.assertIs(returned_response, response)
        self.assertFalse(session.trust_env)
        session.get.assert_called_once_with(
            "https://93.184.216.34/example.jpg?size=large",
            stream=True,
            timeout=15,
            headers={"User-Agent": "test", "Host": "img.dlsite.jp"},
            allow_redirects=False,
        )
        adapter = session.mount.call_args.args[1]
        self.assertEqual(
            "img.dlsite.jp",
            adapter.poolmanager.connection_pool_kw["assert_hostname"],
        )
        self.assertEqual(
            "img.dlsite.jp",
            adapter.poolmanager.connection_pool_kw["server_hostname"],
        )

    def test_redirect_response_is_rejected_without_following(self):
        response = MagicMock(status_code=302)
        session = MagicMock()
        session.get.return_value = response

        with (
            patch(
                "DLSiteScraper.validated_image_target",
                return_value=(
                    urlsplit("https://img.dlsite.jp/example.jpg"),
                    "img.dlsite.jp",
                    ["93.184.216.34"],
                ),
            ),
            patch("DLSiteScraper.requests.Session", return_value=session),
            self.assertRaises(requests.TooManyRedirects),
        ):
            request_image("https://img.dlsite.jp/example.jpg", {})

        self.assertTrue(response.close.called)
        self.assertTrue(session.close.called)


class DLSiteScraperProductFormatTest(unittest.TestCase):
    def work(self, work_type=None, options=None):
        return SimpleNamespace(work_type=work_type, options=options)

    def test_additional_voice_music_and_animation_options_use_their_real_codes(self):
        self.assertEqual(
            ["ACN", "SND", "MS2", "MV2"],
            product_format_codes(
                self.work(
                    WorkType.ACTION,
                    [WorkOption.VOICE, WorkOption.MUSIC, WorkOption.VIDEO],
                )
            ),
        )

    def test_additional_formats_are_suppressed_when_their_mirrored_main_exists(self):
        cases = [
            (WorkType.MUSIC, WorkOption.MUSIC, ["MUS"]),
            (WorkType.VOICE_ASMR, WorkOption.VOICE, ["SOU"]),
            (WorkType.VIDEO, WorkOption.VIDEO, ["MOV"]),
        ]

        for work_type, option, expected in cases:
            with self.subTest(work_type=work_type):
                self.assertEqual(
                    expected,
                    product_format_codes(self.work(work_type, [option])),
                )

    def test_additional_formats_are_kept_when_their_mirrored_main_is_absent(self):
        self.assertEqual(
            ["SND", "MS2", "MV2"],
            product_format_codes(
                self.work(
                    options=[WorkOption.VOICE, WorkOption.MUSIC, WorkOption.VIDEO],
                )
            ),
        )

    def test_non_format_options_are_ignored(self):
        self.assertEqual(
            ["SOU"],
            product_format_codes(
                self.work(
                    WorkType.VOICE_ASMR,
                    [WorkOption.ENGLISH, WorkOption.AI_GENERATED, WorkOption.REVIEWS],
                )
            ),
        )

    def test_additional_formats_preserve_option_order(self):
        self.assertEqual(
            ["ACN", "MS2", "SND", "MV2"],
            product_format_codes(
                self.work(
                    WorkType.ACTION,
                    [WorkOption.MUSIC, WorkOption.VOICE, WorkOption.VIDEO],
                )
            ),
        )

    def test_all_main_product_formats_are_stored_without_special_cases(self):
        for work_type in WorkType:
            with self.subTest(work_type=work_type):
                self.assertEqual(
                    [work_type.value],
                    product_format_codes(self.work(work_type)),
                )

    def test_work_option_serializes_to_its_stable_code(self):
        self.assertEqual("SND", to_serializable(WorkOption.VOICE))

    def test_existing_enum_serialization_contract_excludes_python_internals(self):
        cases = [
            (AgeCategory.R18, {"_value_": 3, "_name_": "R18"}),
            (WorkType.MANGA, {"_value_": "MNG", "_name_": "MANGA"}),
            (BookType.BOOK, {"_value_": "comic", "_name_": "BOOK"}),
        ]

        for enum_value, expected in cases:
            with self.subTest(enum_value=enum_value):
                self.assertEqual(expected, to_serializable(enum_value))

    def test_serialized_enums_do_not_include_python_enum_internals(self):
        serialized = to_serializable(
            SimpleNamespace(
                age_category=AgeCategory.ALL_AGES,
                work_type=WorkType.MANGA,
                book_type=BookType.BOOK,
            )
        )

        self.assertNotIn("__objclass__", str(serialized))
        self.assertNotIn("_sort_order_", str(serialized))


if __name__ == "__main__":
    unittest.main()
