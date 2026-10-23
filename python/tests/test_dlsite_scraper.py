import asyncio
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from dlsite_async.work import AgeCategory, BookType, WorkOption, WorkType
from DLSiteScraper import edition_links, product_format_codes, to_serializable


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


class FakeResponse:
    def __init__(self, payload):
        self.payload = payload

    async def __aenter__(self):
        return self

    async def __aexit__(self, *args):
        return False

    def raise_for_status(self):
        if isinstance(self.payload, Exception):
            raise self.payload

    async def json(self, content_type=None):
        return self.payload


class FakeApi:
    def __init__(self, payloads):
        self.payloads = payloads

    def get(self, url, params=None):
        return FakeResponse(self.payloads[url.split("/")[-1]])


class DLSiteScraperEditionLinksTest(unittest.TestCase):
    work = SimpleNamespace(product_id="RJ01625050", site_id="home")

    def test_edition_links_keep_the_raw_dlsite_fields(self):
        translation = {"original_workno": "RJ01587488", "child_worknos": []}
        editions = [{"workno": "RJ01587488", "edition_type": "language"}]
        api = FakeApi(
            {
                "ajax": {"RJ01625050": {"translation_info": translation}},
                "product.json": [
                    {"workno": "RJ01625050", "language_editions": editions}
                ],
            }
        )

        self.assertEqual(
            {
                "translation_info": translation,
                "editions": [],
                "language_editions": editions,
            },
            asyncio.run(edition_links(api, self.work)),
        )

    def test_edition_links_are_unknown_when_a_request_fails(self):
        api = FakeApi({"ajax": RuntimeError("offline"), "product.json": []})

        self.assertIsNone(asyncio.run(edition_links(api, self.work)))


if __name__ == "__main__":
    unittest.main()
