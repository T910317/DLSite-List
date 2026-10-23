import ipaddress
import json
import shutil
import subprocess
import unittest
from pathlib import Path


class ComposeNetworkTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        standalone = shutil.which("docker-compose")
        docker = shutil.which("docker")
        if standalone:
            cls.compose = [standalone]
        elif docker:
            cls.compose = [docker, "compose"]
        else:
            raise unittest.SkipTest("需要 Docker Compose，僅解析設定，不啟動容器")

    def test_default_and_test_profiles_publish_only_the_local_web_port(self):
        root = Path(__file__).resolve().parents[2]
        for filename in ("compose.yaml", "compose.dev.yaml"):
            for profile in ([], ["--profile", "test"]):
                with self.subTest(compose=filename, profile=profile):
                    result = subprocess.run(
                        self.compose
                        + ["--env-file", "docker/.env.docker", "-f", filename]
                        + profile
                        + ["config", "--format", "json"],
                        cwd=root,
                        capture_output=True,
                        text=True,
                        timeout=30,
                        check=True,
                    )
                    services = json.loads(result.stdout)["services"]
                    published = []
                    for name, service in services.items():
                        self.assertNotEqual("host", service.get("network_mode"))
                        for port in service.get("ports", []):
                            self.assertTrue(
                                ipaddress.ip_address(
                                    port.get("host_ip", "0.0.0.0")
                                ).is_loopback,
                                f"{filename}: {name} 的連接埠未限制於本機",
                            )
                            published.append(
                                (name, port["host_ip"], str(port["published"]),
                                 port["target"], port["protocol"])
                            )
                    self.assertEqual(
                        [("web", "127.0.0.1", "8080", 80, "tcp")], published
                    )


if __name__ == "__main__":
    unittest.main()
