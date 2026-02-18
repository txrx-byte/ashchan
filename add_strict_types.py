import os

def add_strict_types(directory):
    for root, dirs, files in os.walk(directory):
        if 'vendor' in dirs:
            dirs.remove('vendor')
        
        for file in files:
            if file.endswith('.php'):
                filepath = os.path.join(root, file)
                with open(filepath, 'r') as f:
                    content = f.read()
                
                if 'declare(strict_types=1);' not in content:
                    if content.startswith('<?php'):
                        # Check if there's a newline after <?php
                        if content.startswith('<?php\n'):
                             new_content = content.replace('<?php\n', '<?php\n\ndeclare(strict_types=1);\n', 1)
                        elif content.startswith('<?php\r\n'):
                             new_content = content.replace('<?php\r\n', '<?php\r\n\ndeclare(strict_types=1);\r\n', 1)
                        else:
                             # Just append it after <?php
                             new_content = content.replace('<?php', '<?php\n\ndeclare(strict_types=1);\n', 1)
                        
                        with open(filepath, 'w') as f:
                            f.write(new_content)
                        print(f"Updated {filepath}")
                    else:
                        print(f"Skipped {filepath} (does not start with <?php)")
                else:
                    print(f"Skipped {filepath} (already has strict_types)")

if __name__ == "__main__":
    add_strict_types('services')