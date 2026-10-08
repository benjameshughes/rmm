//! `--keep` patterns: folder paths relative to the scan root, matched one
//! folder name at a time, case-insensitively. `*` and `?` never cross a
//! separator, so `Users\*\AppData` matches exactly three levels down.

#[derive(Debug, Clone)]
pub struct KeepPattern {
    segments: Vec<Vec<char>>,
}

impl KeepPattern {
    pub fn parse(glob: &str) -> Self {
        Self {
            segments: glob
                .split(['\\', '/'])
                .filter(|segment| !segment.is_empty())
                .map(|segment| segment.to_lowercase().chars().collect())
                .collect(),
        }
    }

    /// How many folders below the root a match sits.
    pub fn depth(&self) -> usize {
        self.segments.len()
    }

    /// `names` are the folder names from just below the root down to the
    /// candidate folder.
    pub fn matches(&self, names: &[&str]) -> bool {
        if names.len() != self.segments.len() || names.is_empty() {
            return false;
        }
        self.segments.iter().zip(names).all(|(pattern, name)| {
            let name: Vec<char> = name.to_lowercase().chars().collect();
            wildcard_matches(pattern, &name)
        })
    }
}

/// `*` matches any run of characters, `?` exactly one.
fn wildcard_matches(pattern: &[char], text: &[char]) -> bool {
    let (mut p, mut t) = (0, 0);
    let mut backtrack: Option<(usize, usize)> = None;

    while t < text.len() {
        if p < pattern.len() && (pattern[p] == '?' || pattern[p] == text[t]) {
            p += 1;
            t += 1;
        } else if p < pattern.len() && pattern[p] == '*' {
            backtrack = Some((p, t));
            p += 1;
        } else if let Some((star, matched)) = backtrack {
            p = star + 1;
            t = matched + 1;
            backtrack = Some((star, matched + 1));
        } else {
            return false;
        }
    }

    pattern[p..].iter().all(|&c| c == '*')
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn matches_one_folder_per_star() {
        let pattern = KeepPattern::parse(r"Users\*\AppData\Local\Temp");

        assert!(pattern.matches(&["Users", "ben", "AppData", "Local", "Temp"]));
        assert!(pattern.matches(&["users", "Sophie", "appdata", "local", "TEMP"]));
        assert!(!pattern.matches(&["Users", "ben", "AppData", "Local"]));
        assert!(!pattern.matches(&["Users", "a", "b", "AppData", "Local", "Temp"]));
    }

    #[test]
    fn supports_partial_wildcards_and_forward_slashes() {
        let pattern = KeepPattern::parse("Program Files*/Micro?oft*");

        assert!(pattern.matches(&["Program Files (x86)", "Microsoft Office"]));
        assert!(pattern.matches(&["Program Files", "Microsoft"]));
        assert!(!pattern.matches(&["Program Files", "Mcrosoft"]));
        assert_eq!(pattern.depth(), 2);
    }

    #[test]
    fn an_empty_pattern_matches_nothing() {
        assert!(!KeepPattern::parse("").matches(&[]));
    }
}
