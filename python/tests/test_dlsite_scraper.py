import asyncio
import sys
import unittest
from datetime import datetime
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from dlsite_async._scraper import parse_work_html
from dlsite_async.work import AgeCategory, BookType, WorkOption, WorkType
from DLSiteScraper import (
    edition_links,
    product_format_codes,
    rating_fields,
    to_serializable,
    work_date_fields,
)


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



class FakeRatingApi:
    def __init__(self, payload):
        self.payload = payload
        self.requests = []

    def get(self, url, params=None):
        self.requests.append((url, params))
        return FakeResponse(self.payload)


class DLSiteScraperRatingTest(unittest.TestCase):
    product_id = "RJ01741555"

    def rating(self, payload):
        return asyncio.run(rating_fields(FakeRatingApi(payload), self.product_id))

    def test_rating_fields_keep_the_dlsite_average_and_count(self):
        api = FakeRatingApi(
            {
                self.product_id: {
                    "rate_average": 5,
                    "rate_average_2dp": 4.96,
                    "rate_average_star": 50,
                    "rate_count": 57,
                }
            }
        )

        self.assertEqual(
            {"rate_average_2dp": 4.96, "rate_count": 57},
            asyncio.run(rating_fields(api, self.product_id)),
        )
        self.assertEqual(
            [
                (
                    "https://www.dlsite.com/maniax/product/info/ajax",
                    {"product_id": self.product_id},
                )
            ],
            api.requests,
        )

    def test_unrated_works_report_no_rating(self):
        self.assertEqual(
            {"rate_average_2dp": None, "rate_count": None},
            self.rating(
                {
                    self.product_id: {
                        "rate_average": None,
                        "rate_average_2dp": None,
                        "rate_count": None,
                    }
                }
            ),
        )

    def test_rating_is_unknown_when_the_request_fails(self):
        self.assertIsNone(self.rating(RuntimeError("offline")))

    def test_rating_is_unknown_when_dlsite_returns_no_work_or_fields(self):
        # DLsite answers [] for a work it cannot find.
        self.assertIsNone(self.rating([]))
        self.assertIsNone(self.rating({"RJ00000001": {"rate_count": 3}}))
        self.assertIsNone(self.rating({self.product_id: {"dl_count": 3}}))


class DLSiteScraperGenreLabelTest(unittest.TestCase):
    def test_zh_tw_genre_row_labelled_bunrui_is_parsed_as_genre(self):
        # zh_TW work pages label the genre row "分類", not "ジャンル" or "Genre".
        content = """
        <table id="work_outline">
          <tr>
            <th>分類</th>
            <td>
              <div class="main_genre">
                <a href="/maniax/fsr/=/genre/496">環繞音</a>
                <a href="/maniax/fsr/=/genre/207">酪梨</a>
              </div>
            </td>
          </tr>
        </table>
        """

        self.assertEqual(["環繞音", "酪梨"], parse_work_html(content)["genre"])


class DLSiteScraperWorkDateTest(unittest.TestCase):
    def test_release_and_update_dates_are_written_as_plain_text(self):
        work = SimpleNamespace(
            regist_date=datetime(2025, 11, 22, 0, 0, 0),
            modified_date=datetime(2026, 3, 7, 9, 30, 15),
        )

        self.assertEqual(
            {
                "regist_date": "2025-11-22 00:00:00",
                "modified_date": "2026-03-07 09:30:15",
            },
            work_date_fields(work),
        )

    def test_unknown_dates_are_written_as_null(self):
        work = SimpleNamespace(regist_date=None, modified_date=None)

        self.assertEqual(
            {"regist_date": None, "modified_date": None},
            work_date_fields(work),
        )

    def test_missing_date_attributes_are_written_as_null(self):
        self.assertEqual(
            {"regist_date": None, "modified_date": None},
            work_date_fields(SimpleNamespace()),
        )

    def test_dates_that_are_not_datetimes_keep_their_text(self):
        work = SimpleNamespace(regist_date="2025-11-22", modified_date=None)

        self.assertEqual(
            {"regist_date": "2025-11-22", "modified_date": None},
            work_date_fields(work),
        )

    def test_datetime_serializes_to_the_same_text_as_the_date_fields(self):
        self.assertEqual(
            "2025-11-22 00:00:00",
            to_serializable(datetime(2025, 11, 22, 0, 0, 0)),
        )


if __name__ == "__main__":
    unittest.main()
